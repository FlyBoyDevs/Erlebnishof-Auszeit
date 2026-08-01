#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import {
    lstat,
    mkdir,
    readFile,
    readdir,
    realpath,
    rename,
    unlink,
    writeFile,
} from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import sharp from 'sharp';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const IMAGE_ROOT = path.join(ROOT, 'img');
const OUTPUT_ROOT = path.join(IMAGE_ROOT, 'opt');
const MANIFEST_PATH = path.join(OUTPUT_ROOT, 'manifest.json');
const MEDIA_CONFIG_PATH = path.join(ROOT, 'data', 'media.json');

// img/news is intentionally excluded: editorial uploads use the PHP pipeline.
const DEFAULT_SOURCE_DIRS = [
  'img/home',
  'img/menu',
  'img/hofladen',
  'img/events',
  'img/animals',
];
const DEFAULT_WIDTHS = [320, 640, 1024, 1600];
const DEFAULT_MAX_VARIANT_BYTES = 1_500_000;
const MAX_INPUT_PIXELS = 100_000_000;
const MANIFEST_SCHEMA_VERSION = 1;
const PIPELINE_VERSION = 2;
const SUPPORTED_SOURCE_EXTENSIONS = new Set(['.jpg', '.jpeg', '.png', '.webp']);

const FORMAT_SPECS = [
  {
    format: 'avif',
    extension: 'avif',
    options: { quality: 50, effort: 3 },
  },
  {
    format: 'webp',
    extension: 'webp',
    options: { quality: 80, effort: 4, smartSubsample: true },
  },
  {
    format: 'jpeg',
    extension: 'jpg',
    options: {
      quality: 78,
      mozjpeg: true,
      progressive: true,
      chromaSubsampling: '4:2:0',
    },
  },
];

function printHelp() {
  console.log(`Usage: node tools/optimize-images.mjs [options]

Build collision-safe, content-hashed responsive images and img/opt/manifest.json.
Names use <slug>-<source-ext>-i<identity>-c<content>-r<recipe>-<width>.<format>.

Options:
  --check                 Validate sources, manifest, and generated files; write nothing
  --orphans               List files in img/opt not referenced by the manifest; write nothing
  --prune                 Delete only unreferenced content-hashed generated variants
  --dir <img/path>        Limit a build/check to a managed source directory (repeatable)
  --widths <list>         Comma-separated widths (default: ${DEFAULT_WIDTHS.join(',')})
  --max-bytes <number>    Maximum bytes per generated variant (default: ${DEFAULT_MAX_VARIANT_BYTES})
  --force                 Regenerate selected variants even when validation passes
  --help                  Show this help

Examples:
  npm --prefix tools run images:build
  npm --prefix tools run images:check
  npm --prefix tools run images:orphans
  node tools/optimize-images.mjs --dir img/menu --force`);
}

function fail(message) {
  throw new Error(message);
}

function parsePositiveInteger(value, label) {
  if (!/^\d+$/.test(value ?? '')) fail(`${label} must be a positive integer`);
  const parsed = Number(value);
  if (!Number.isSafeInteger(parsed) || parsed <= 0) fail(`${label} must be a positive integer`);
  return parsed;
}

function parseArgs(argv) {
  const options = {
    mode: 'build',
    force: false,
    explicitDirs: [],
    widths: [...DEFAULT_WIDTHS],
    widthsExplicit: false,
    maxVariantBytes: DEFAULT_MAX_VARIANT_BYTES,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === '--help' || argument === '-h') {
      options.help = true;
    } else if (argument === '--check') {
      if (options.mode !== 'build') fail('--check and --orphans cannot be combined');
      options.mode = 'check';
    } else if (argument === '--orphans') {
      if (options.mode !== 'build') fail('--check and --orphans cannot be combined');
      options.mode = 'orphans';
    } else if (argument === '--prune') {
      if (options.mode !== 'build') fail('--check, --orphans, and --prune cannot be combined');
      options.mode = 'prune';
    } else if (argument === '--force') {
      options.force = true;
    } else if (argument === '--dir' || argument.startsWith('--dir=')) {
      const value = argument === '--dir' ? argv[++index] : argument.slice('--dir='.length);
      if (!value) fail('--dir requires a path');
      options.explicitDirs.push(value);
    } else if (argument === '--widths' || argument.startsWith('--widths=')) {
      const value = argument === '--widths' ? argv[++index] : argument.slice('--widths='.length);
      if (!value) fail('--widths requires a comma-separated list');
      options.widths = [...new Set(value.split(',').map((item) => parsePositiveInteger(item.trim(), '--widths')))]
        .sort((left, right) => left - right);
      options.widthsExplicit = true;
    } else if (argument === '--max-bytes' || argument.startsWith('--max-bytes=')) {
      const value = argument === '--max-bytes' ? argv[++index] : argument.slice('--max-bytes='.length);
      options.maxVariantBytes = parsePositiveInteger(value, '--max-bytes');
    } else {
      fail(`unknown option: ${argument}`);
    }
  }

  if (options.force && options.mode !== 'build') fail('--force is only valid in build mode');
  if ((options.mode === 'orphans' || options.mode === 'prune')
      && (options.explicitDirs.length > 0 || options.widths.join(',') !== DEFAULT_WIDTHS.join(','))) {
    fail(`${options.mode === 'prune' ? '--prune' : '--orphans'} does not accept --dir or --widths`);
  }
  return options;
}

function toPosix(relativePath) {
  return relativePath.split(path.sep).join('/').normalize('NFC');
}

function isWithin(candidate, parent) {
  const relative = path.relative(parent, candidate);
  return relative === '' || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative));
}

function normalizeManagedDirectory(value) {
  const absolute = path.resolve(ROOT, value);
  const allowedRoots = DEFAULT_SOURCE_DIRS.map((directory) => path.resolve(ROOT, directory));
  if (!allowedRoots.some((allowedRoot) => isWithin(absolute, allowedRoot))) {
    fail(`--dir must be inside one of: ${DEFAULT_SOURCE_DIRS.join(', ')}`);
  }
  return absolute;
}

function compareText(left, right) {
  return left < right ? -1 : left > right ? 1 : 0;
}

function uniqueDirectories(values) {
  const directories = [...new Set(values.map(normalizeManagedDirectory))].sort(compareText);
  return directories.filter(
    (directory, index) => !directories.some((parent, parentIndex) => parentIndex !== index && isWithin(directory, parent)),
  );
}

function hashBuffer(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function hashText(value) {
  return hashBuffer(Buffer.from(value, 'utf8'));
}

async function loadMediaConfig() {
  let config;
  try {
    config = JSON.parse(await readFile(MEDIA_CONFIG_PATH, 'utf8'));
  } catch (error) {
    fail(`cannot read data/media.json: ${error.message}`);
  }
  if (config?.schemaVersion !== 1 || !Array.isArray(config.assets) || config.assets.length === 0) {
    fail('data/media.json must contain a non-empty schemaVersion 1 assets list');
  }

  const ids = new Set();
  const sources = new Set();
  for (const asset of config.assets) {
    if (!asset || typeof asset !== 'object' || Array.isArray(asset)) fail('invalid media asset record');
    if (!/^[a-z0-9][a-z0-9-]{0,79}$/.test(asset.id ?? '') || ids.has(asset.id)) fail(`invalid or duplicate media asset id: ${asset.id ?? ''}`);
    if (typeof asset.source !== 'string' || sources.has(asset.source)) fail(`invalid or duplicate media source: ${asset.source ?? ''}`);
    const absolute = path.resolve(ROOT, asset.source);
    const allowedRoots = DEFAULT_SOURCE_DIRS.map((directory) => path.resolve(ROOT, directory));
    if (!allowedRoots.some((allowedRoot) => isWithin(absolute, allowedRoot))) fail(`media source is outside managed directories: ${asset.source}`);
    if (!SUPPORTED_SOURCE_EXTENSIONS.has(path.extname(asset.source).toLowerCase())) fail(`unsupported configured source: ${asset.source}`);
    if (!Array.isArray(asset.slots) || asset.slots.length === 0 || !asset.slots.every((slot) => typeof slot === 'string' && slot.length <= 80)) fail(`invalid slots for ${asset.id}`);
    if (!Array.isArray(asset.widths) || asset.widths.length === 0) fail(`invalid widths for ${asset.id}`);
    asset.widths = [...new Set(asset.widths.map((width) => parsePositiveInteger(String(width), `width in ${asset.id}`)))].sort((left, right) => left - right);
    asset.maximumVariantBytes = parsePositiveInteger(String(asset.maximumVariantBytes), `maximumVariantBytes in ${asset.id}`);
    if (typeof asset.alt !== 'string' || asset.alt.length > 300) fail(`invalid alt text for ${asset.id}`);
    ids.add(asset.id);
    sources.add(asset.source);
  }
  return config;
}

async function hashFile(filePath) {
  const hash = createHash('sha256');
  for await (const chunk of createReadStream(filePath)) hash.update(chunk);
  return hash.digest('hex');
}

async function pathExists(filePath) {
  try {
    await lstat(filePath);
    return true;
  } catch (error) {
    if (error.code === 'ENOENT') return false;
    throw error;
  }
}

async function ensureSafeOutputDirectory(directory) {
  if (!isWithin(directory, OUTPUT_ROOT)) fail(`unsafe output directory: ${directory}`);
  const imageRootDetails = await lstat(IMAGE_ROOT);
  if (!imageRootDetails.isDirectory() || imageRootDetails.isSymbolicLink()) fail('img is not a safe source directory');
  const relative = path.relative(IMAGE_ROOT, directory);
  let cursor = IMAGE_ROOT;
  for (const segment of relative.split(path.sep).filter(Boolean)) {
    cursor = path.join(cursor, segment);
    let details;
    try {
      details = await lstat(cursor);
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
      await mkdir(cursor);
      details = await lstat(cursor);
    }
    if (!details.isDirectory() || details.isSymbolicLink()) {
      fail(`refusing unsafe output-directory component: ${toPosix(path.relative(ROOT, cursor))}`);
    }
  }
}

async function discoverSources(directory) {
  const discovered = [];

  async function visit(current) {
    const entries = await readdir(current, { withFileTypes: true });
    entries.sort((left, right) => compareText(left.name, right.name));

    for (const entry of entries) {
      if (entry.name.startsWith('.')) continue;
      const absolute = path.join(current, entry.name);
      if (entry.isSymbolicLink()) fail(`refusing to follow source symlink: ${toPosix(path.relative(ROOT, absolute))}`);
      if (entry.isDirectory()) {
        await visit(absolute);
      } else if (entry.isFile() && SUPPORTED_SOURCE_EXTENSIONS.has(path.extname(entry.name).toLowerCase())) {
        discovered.push(absolute);
      }
    }
  }

  const details = await lstat(directory).catch((error) => {
    if (error.code === 'ENOENT') fail(`source directory does not exist: ${toPosix(path.relative(ROOT, directory))}`);
    throw error;
  });
  if (!details.isDirectory() || details.isSymbolicLink()) fail(`source directory is not a safe directory: ${directory}`);
  await visit(directory);
  return discovered;
}

function safeSegment(value) {
  const normalized = value
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return (normalized || 'asset').slice(0, 48);
}

function generatorConfig(options, mediaConfig) {
    const recipe = {
        pipelineVersion: PIPELINE_VERSION,
        runtime: {
            node: process.versions.node,
            platform: process.platform,
            architecture: process.arch,
        },
        sharpVersions: Object.fromEntries(Object.entries(sharp.versions).sort(([left], [right]) => compareText(left, right))),
        widths: options.widths,
        formats: FORMAT_SPECS,
        autoOrient: true,
        stripMetadata: true,
        colourspace: 'srgb',
        resize: {withoutEnlargement: true, fit: 'inside'},
        jpegFlattenBackground: '#ffffff',
        maximumInputPixels: MAX_INPUT_PIXELS,
    };
    return {
        name: 'tools/optimize-images.mjs',
        pipelineVersion: PIPELINE_VERSION,
        runtime: recipe.runtime,
        sharpVersions: recipe.sharpVersions,
        widths: options.widths,
        widthMode: options.widthsExplicit ? 'command-line-override' : 'media-config',
        mediaConfig: 'data/media.json',
        mediaConfigHash: hashText(JSON.stringify(mediaConfig)),
        formats: FORMAT_SPECS.map(({ format, extension, options: formatOptions }) => ({
          format,
          extension,
          options: formatOptions,
        })),
        recipeHash: hashText(JSON.stringify(recipe)).slice(0, 12),
        maximumVariantBytes: options.maxVariantBytes,
  };
}

function outputDimensions(metadata) {
  const width = metadata.autoOrient?.width
    ?? ((metadata.orientation ?? 1) >= 5 ? metadata.height : metadata.width);
  const height = metadata.autoOrient?.height
    ?? ((metadata.orientation ?? 1) >= 5 ? metadata.width : metadata.height);
  return { width, height };
}

async function createSourcePlan(absolutePath, asset, options, generator) {
  const sourceBuffer = await readFile(absolutePath);
  let metadata;
  try {
    metadata = await sharp(sourceBuffer, { failOn: 'error', limitInputPixels: MAX_INPUT_PIXELS }).metadata();
  } catch (error) {
    fail(`cannot decode ${toPosix(path.relative(ROOT, absolutePath))}: ${error.message}`);
  }

  const oriented = outputDimensions(metadata);
  if (!Number.isInteger(oriented.width) || !Number.isInteger(oriented.height)) {
    fail(`missing dimensions for ${toPosix(path.relative(ROOT, absolutePath))}`);
  }

  const identity = toPosix(path.relative(ROOT, absolutePath));
  const relativeFromImages = toPosix(path.relative(IMAGE_ROOT, absolutePath));
  const identityHash = hashText(identity).slice(0, 12);
  const contentHash = hashBuffer(sourceBuffer);
  const sourceExtension = path.extname(absolutePath).slice(1).toLowerCase();
  const sourceStem = safeSegment(path.basename(absolutePath, path.extname(absolutePath)));
  const sourceDirectory = path.posix.dirname(relativeFromImages);
  const safeDirectory = sourceDirectory === '.'
    ? ''
    : sourceDirectory.split('/').map(safeSegment).join('/');
  const outputDirectory = path.posix.join('img/opt', safeDirectory);
  const outputPrefix = `${sourceStem}-${sourceExtension}-i${identityHash}-c${contentHash.slice(0, 20)}-r${generator.recipeHash}`;

  const requestedWidths = options.widthsExplicit ? options.widths : asset.widths;
  const widths = requestedWidths.filter((width) => width <= oriented.width);
  const maximumWidth = Math.min(oriented.width, requestedWidths.at(-1));
  if (!widths.includes(maximumWidth)) widths.push(maximumWidth);
  widths.sort((left, right) => left - right);

  const variants = [];
  for (const width of widths) {
    for (const format of FORMAT_SPECS) {
      variants.push({
        path: path.posix.join(outputDirectory, `${outputPrefix}-${width}.${format.extension}`),
        format: format.format,
        width,
        expectedHeight: Math.round((oriented.height * width) / oriented.width),
      });
    }
  }

  return {
    absolutePath,
    sourceBuffer,
    maximumVariantBytes: Math.min(options.maxVariantBytes, asset.maximumVariantBytes),
    source: {
      path: identity,
      identityHash,
      contentHash,
      bytes: sourceBuffer.length,
      format: metadata.format,
      width: metadata.width,
      height: metadata.height,
      orientedWidth: oriented.width,
      orientedHeight: oriented.height,
      orientation: metadata.orientation ?? 1,
      usage: {
        id: asset.id,
        slots: asset.slots,
        alt: asset.alt,
        maximumVariantBytes: asset.maximumVariantBytes,
      },
    },
    variants,
  };
}

function assertNoOutputCollisions(plans) {
  const paths = new Map();
  for (const plan of plans) {
    for (const variant of plan.variants) {
      const previous = paths.get(variant.path);
      if (previous) fail(`output collision: ${variant.path} from ${previous} and ${plan.source.path}`);
      paths.set(variant.path, plan.source.path);
    }
  }
}

function expectedSharpFormat(format) {
  if (format === 'avif') return { format: 'heif', mediaType: 'image/avif', compression: 'av1' };
  if (format === 'jpeg') return { format: 'jpeg', mediaType: 'image/jpeg' };
  return { format: 'webp', mediaType: 'image/webp' };
}

async function inspectVariant(filePath, expected, maximumBytes) {
  const problems = [];
  let details;
  try {
    details = await lstat(filePath);
  } catch (error) {
    if (error.code === 'ENOENT') return { problems: [`missing ${expected.path}`] };
    throw error;
  }
  if (!details.isFile() || details.isSymbolicLink()) {
    return { problems: [`not a regular generated file: ${expected.path}`] };
  }
  if (details.size > maximumBytes) {
    problems.push(`${expected.path} is ${details.size} bytes (limit ${maximumBytes})`);
  }

  let metadata;
  try {
    metadata = await sharp(filePath, { failOn: 'error', limitInputPixels: MAX_INPUT_PIXELS }).metadata();
  } catch (error) {
    return { problems: [...problems, `cannot decode ${expected.path}: ${error.message}`] };
  }

  const expectedFormat = expectedSharpFormat(expected.format);
  if (metadata.format !== expectedFormat.format || metadata.mediaType !== expectedFormat.mediaType) {
    problems.push(`${expected.path} has format ${metadata.mediaType ?? metadata.format}, expected ${expectedFormat.mediaType}`);
  }
  if (expectedFormat.compression && metadata.compression !== expectedFormat.compression) {
    problems.push(`${expected.path} is not AV1-compressed AVIF`);
  }
  // libvips may choose either neighbouring integer when preserving a fractional aspect ratio.
  if (metadata.width !== expected.width || Math.abs(metadata.height - expected.expectedHeight) > 1) {
    problems.push(
      `${expected.path} is ${metadata.width}x${metadata.height}, expected ${expected.width}x${expected.expectedHeight}`,
    );
  }
  const retainedMetadata = ['exif', 'icc', 'iptc', 'xmp'].filter((key) => metadata[key] !== undefined);
  if (retainedMetadata.length > 0 || (metadata.orientation !== undefined && metadata.orientation !== 1)) {
    problems.push(`${expected.path} retains metadata (${[...retainedMetadata, metadata.orientation ? 'orientation' : null].filter(Boolean).join(', ')})`);
  }

  return {
    problems,
    record: {
      path: expected.path,
      format: expected.format,
      width: metadata.width,
      height: metadata.height,
      bytes: details.size,
      sha256: await hashFile(filePath),
    },
  };
}

function sameRecord(actual, expected) {
  return actual
    && expected
    && actual.path === expected.path
    && actual.format === expected.format
    && actual.width === expected.width
    && actual.height === expected.height
    && actual.bytes === expected.bytes
    && actual.sha256 === expected.sha256;
}

async function renderVariant(plan, variant, maximumBytes) {
  const format = FORMAT_SPECS.find((candidate) => candidate.format === variant.format);
  const outputPath = path.resolve(ROOT, variant.path);
  if (!isWithin(outputPath, OUTPUT_ROOT)) fail(`unsafe output path: ${variant.path}`);
  await ensureSafeOutputDirectory(path.dirname(outputPath));
  const temporaryPath = path.join(
    path.dirname(outputPath),
    `.${path.basename(outputPath)}.${process.pid}-${Date.now()}.tmp`,
  );

  let pipeline = sharp(plan.sourceBuffer, { failOn: 'error', limitInputPixels: MAX_INPUT_PIXELS })
    .autoOrient()
    .resize({ width: variant.width, withoutEnlargement: true, fit: 'inside' })
    .toColourspace('srgb');
  if (variant.format === 'jpeg') pipeline = pipeline.flatten({ background: '#ffffff' });
  pipeline = pipeline.toFormat(format.format, format.options);

  try {
    await pipeline.toFile(temporaryPath);
    const inspected = await inspectVariant(temporaryPath, variant, maximumBytes);
    if (inspected.problems.length > 0) fail(inspected.problems.join('\n'));
    await rename(temporaryPath, outputPath);
    return inspected.record;
  } catch (error) {
    await unlink(temporaryPath).catch(() => {});
    throw error;
  }
}

async function assertSourcesUnchanged(plans) {
  for (const plan of plans) {
    const currentHash = await hashFile(plan.absolutePath);
    if (currentHash !== plan.source.contentHash) {
      fail(`source changed during image operation: ${plan.source.path}; run the command again`);
    }
  }
}

async function readManifest({ required = false } = {}) {
  try {
    return JSON.parse(await readFile(MANIFEST_PATH, 'utf8'));
  } catch (error) {
    if (error.code === 'ENOENT' && !required) return null;
    if (error.code === 'ENOENT') fail('img/opt/manifest.json is missing; run the image build first');
    fail(`cannot read img/opt/manifest.json: ${error.message}`);
  }
}

function compatibleGenerator(manifest, generator) {
  return manifest?.schemaVersion === MANIFEST_SCHEMA_VERSION
    && JSON.stringify(manifest.generator) === JSON.stringify(generator);
}

function sourceInScopes(sourcePath, scopes) {
  const absolute = path.resolve(ROOT, sourcePath);
  return scopes.some((scope) => isWithin(absolute, scope));
}

function manifestDocument(generator, sources) {
  return {
    schemaVersion: MANIFEST_SCHEMA_VERSION,
    generator,
    sources: [...sources].sort((left, right) => compareText(left.source.path, right.source.path)),
  };
}

async function writeManifest(manifest) {
  await ensureSafeOutputDirectory(OUTPUT_ROOT);
  const temporaryPath = path.join(OUTPUT_ROOT, `.manifest.${process.pid}-${Date.now()}.tmp`);
  await writeFile(temporaryPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
  await rename(temporaryPath, MANIFEST_PATH);
}

async function makePlans(scopes, options, generator, mediaConfig) {
  const assets = mediaConfig.assets
    .filter((asset) => sourceInScopes(asset.source, scopes))
    .sort((left, right) => compareText(left.source, right.source));
  const plans = [];
  for (const asset of assets) {
    const sourcePath = path.resolve(ROOT, asset.source);
    const details = await lstat(sourcePath).catch((error) => {
      if (error.code === 'ENOENT') fail(`configured source does not exist: ${asset.source}`);
      throw error;
    });
    if (!details.isFile() || details.isSymbolicLink()) fail(`configured source is not a safe regular file: ${asset.source}`);
    if (await realpath(sourcePath) !== sourcePath) fail(`configured source traverses a symlink: ${asset.source}`);
    plans.push(await createSourcePlan(sourcePath, asset, options, generator));
  }
  assertNoOutputCollisions(plans);
  return plans;
}

function existingSourceMap(manifest) {
  return new Map((manifest?.sources ?? []).map((entry) => [entry.source.path, entry]));
}

async function build(options, scopes, partial, mediaConfig) {
  const generator = generatorConfig(options, mediaConfig);
  const oldManifest = await readManifest();
  if (partial && oldManifest && !compatibleGenerator(oldManifest, generator)) {
    fail('partial build cannot change the image recipe; run a full build without --dir');
  }
  if (partial && !oldManifest) fail('partial build requires an existing manifest; run a full build first');

  const plans = await makePlans(scopes, options, generator, mediaConfig);
  const oldSources = existingSourceMap(oldManifest);
  const rebuilt = [];
  let generated = 0;
  let reused = 0;

  for (const plan of plans) {
    const previous = oldSources.get(plan.source.path);
    const previousVariants = new Map((previous?.variants ?? []).map((variant) => [variant.path, variant]));
    const variants = [];
    for (const variant of plan.variants) {
      const outputPath = path.resolve(ROOT, variant.path);
      const previousVariant = previousVariants.get(variant.path);
      let inspected = null;
      if (!options.force
        && previous?.source.contentHash === plan.source.contentHash
        && previous?.source.identityHash === plan.source.identityHash
        && await pathExists(outputPath)) {
        inspected = await inspectVariant(outputPath, variant, plan.maximumVariantBytes);
      }

      if (inspected
        && inspected.problems.length === 0
        && sameRecord(inspected.record, previousVariant)) {
        variants.push(inspected.record);
        reused += 1;
      } else {
        variants.push(await renderVariant(plan, variant, plan.maximumVariantBytes));
        generated += 1;
      }
    }
    rebuilt.push({ source: plan.source, variants });
  }

  const preserved = partial
    ? oldManifest.sources.filter((entry) => !sourceInScopes(entry.source.path, scopes))
    : [];
  const mergedSources = [...preserved, ...rebuilt];
  const mergedPaths = new Map();
  for (const entry of mergedSources) {
    for (const variant of entry.variants) {
      const previous = mergedPaths.get(variant.path);
      if (previous) fail(`output collision: ${variant.path} from ${previous} and ${entry.source.path}`);
      mergedPaths.set(variant.path, entry.source.path);
    }
  }
  await assertSourcesUnchanged(plans);
  const manifest = manifestDocument(generator, mergedSources);
  await writeManifest(manifest);
  const orphans = await findOrphans(manifest);
  await assertSourcesUnchanged(plans);

  console.log(`image build: ${rebuilt.length} source(s), ${generated} generated, ${reused} reused`);
  console.log(`manifest: ${toPosix(path.relative(ROOT, MANIFEST_PATH))} (${manifest.sources.length} source(s), ${manifest.sources.reduce((sum, entry) => sum + entry.variants.length, 0)} variant(s))`);
  reportOrphans(orphans, false);
}

async function verify(options, scopes, partial, mediaConfig) {
  const generator = generatorConfig(options, mediaConfig);
  const manifest = await readManifest({ required: true });
  const errors = [];
  if (!compatibleGenerator(manifest, generator)) {
    errors.push('manifest generator/recipe does not match this pinned tool configuration');
  }

  const plans = await makePlans(scopes, options, generator, mediaConfig);
  const manifestSources = existingSourceMap(manifest);
  const actualEntries = [];

  for (const plan of plans) {
    const variants = [];
    for (const variant of plan.variants) {
      const inspected = await inspectVariant(path.resolve(ROOT, variant.path), variant, plan.maximumVariantBytes);
      errors.push(...inspected.problems);
      if (inspected.record) variants.push(inspected.record);
    }
    actualEntries.push({ source: plan.source, variants });
  }

  for (const entry of actualEntries) {
    const recorded = manifestSources.get(entry.source.path);
    if (!recorded) {
      errors.push(`manifest is missing source ${entry.source.path}`);
    } else if (JSON.stringify(recorded) !== JSON.stringify(entry)) {
      errors.push(`manifest is stale for ${entry.source.path}`);
    }
  }

  const scopedRecordedSources = manifest.sources.filter((entry) => sourceInScopes(entry.source.path, scopes));
  if (scopedRecordedSources.length !== actualEntries.length) {
    const current = new Set(actualEntries.map((entry) => entry.source.path));
    for (const recorded of scopedRecordedSources) {
      if (!current.has(recorded.source.path)) errors.push(`manifest retains removed source ${recorded.source.path}`);
    }
  }
  if (!partial && manifest.sources.length !== actualEntries.length) {
    errors.push(`manifest contains ${manifest.sources.length} sources, but ${actualEntries.length} managed sources exist`);
  }

  const orphans = await findOrphans(manifest);
  await assertSourcesUnchanged(plans);
  if (errors.length > 0) {
    for (const error of errors) console.error(`ERROR: ${error}`);
    reportOrphans(orphans, false);
    process.exitCode = 1;
    return;
  }

  const variantCount = actualEntries.reduce((sum, entry) => sum + entry.variants.length, 0);
  console.log(`image check: OK — ${actualEntries.length} source(s), ${variantCount} variant(s), manifest current`);
  reportOrphans(orphans, false);
}

async function walkOutputFiles() {
  if (!await pathExists(OUTPUT_ROOT)) return [];
  const rootDetails = await lstat(OUTPUT_ROOT);
  if (!rootDetails.isDirectory() || rootDetails.isSymbolicLink()) fail('img/opt is not a safe output directory');
  const files = [];
  async function visit(current) {
    const entries = await readdir(current, { withFileTypes: true });
    entries.sort((left, right) => compareText(left.name, right.name));
    for (const entry of entries) {
      const absolute = path.join(current, entry.name);
      if (entry.isDirectory() && !entry.isSymbolicLink()) {
        await visit(absolute);
      } else {
        files.push(toPosix(path.relative(ROOT, absolute)));
      }
    }
  }
  await visit(OUTPUT_ROOT);
  return files;
}

async function findOrphans(manifest) {
  const referenced = new Set(['img/opt/manifest.json']);
  for (const source of manifest?.sources ?? []) {
    for (const variant of source.variants ?? []) referenced.add(variant.path);
  }
  return (await walkOutputFiles()).filter((file) => !referenced.has(file));
}

function reportOrphans(orphans, listAll) {
  if (orphans.length === 0) {
    console.log('orphans: none');
    return;
  }
  const shown = listAll ? orphans : orphans.slice(0, 12);
  console.warn(`orphans: ${orphans.length} unreferenced file(s); no files were deleted`);
  for (const orphan of shown) console.warn(`  ${orphan}`);
  if (!listAll && shown.length < orphans.length) {
    console.warn(`  … ${orphans.length - shown.length} more (run npm --prefix tools run images:orphans)`);
  }
}

async function pruneGeneratedOrphans(manifest) {
  const generatedPattern = /^img\/opt\/(?:[a-z0-9-]+\/)+[a-z0-9-]+-[a-z0-9]+-i[0-9a-f]{12}-c[0-9a-f]{20}-r[0-9a-f]{12}-\d+\.(?:avif|webp|jpg)$/;
  const orphans = await findOrphans(manifest);
  const removable = orphans.filter((file) => generatedPattern.test(file));
  for (const file of removable) {
    const absolute = path.resolve(ROOT, file);
    if (!isWithin(absolute, OUTPUT_ROOT)) fail(`unsafe orphan path: ${file}`);
    const details = await lstat(absolute);
    if (!details.isFile() || details.isSymbolicLink()) fail(`refusing to prune non-regular output: ${file}`);
    await unlink(absolute);
  }
  console.log(`pruned ${removable.length} reproducible content-hashed orphan(s)`);
  reportOrphans(await findOrphans(manifest), false);
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  if (options.help) {
    printHelp();
    return;
  }

  if (options.mode === 'orphans') {
    const manifest = await readManifest({ required: true });
    reportOrphans(await findOrphans(manifest), true);
    return;
  }
  if (options.mode === 'prune') {
    const manifest = await readManifest({ required: true });
    await pruneGeneratedOrphans(manifest);
    return;
  }

  const mediaConfig = await loadMediaConfig();
  const partial = options.explicitDirs.length > 0;
  const scopes = uniqueDirectories(partial ? options.explicitDirs : DEFAULT_SOURCE_DIRS);
  if (options.mode === 'check') await verify(options, scopes, partial, mediaConfig);
  else await build(options, scopes, partial, mediaConfig);
}

main().catch((error) => {
  console.error(`image pipeline failed: ${error.message}`);
  process.exitCode = 1;
});
