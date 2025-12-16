<?php
require __DIR__ . '/config.php';

// Ensure config variables exist (map constants to variables if config defines constants)
// and provide safe defaults for static analysis and runtime.
if (!isset($NEWS_DIR) && defined('NEWS_DIR')) {
    $NEWS_DIR = NEWS_DIR;
}
if (!isset($ALLOWED_EXTENSIONS) && defined('ALLOWED_EXTENSIONS')) {
    $ALLOWED_EXTENSIONS = ALLOWED_EXTENSIONS;
}
if (!isset($MANIFEST_FILE) && defined('MANIFEST_FILE')) {
    $MANIFEST_FILE = MANIFEST_FILE;
}
$ALLOWED_EXTENSIONS = $ALLOWED_EXTENSIONS ?? ['jpg','jpeg','png','gif','webp'];

// Add translations (German) and helper early so login flow can use t()
$TRANSLATIONS = [
    'News Admin Login' => 'News Admin – Anmeldung',
    'News Admin' => 'News Admin',
    'Username' => 'Benutzername',
    'Password' => 'Passwort',
    'Login' => 'Anmelden',
    'Invalid username or password.' => 'Ungültiger Benutzername oder Passwort.',
    'Logout' => 'Abmelden',
    'Upload new image' => 'Neues Bild hochladen',
    'Upload' => 'Hochladen',
    'Images in <code>news</code> folder' => 'Bilder im Ordner <code>news</code>',
    'No images found in the <code>news</code> folder.' => 'Keine Bilder im Ordner <code>news</code> gefunden.',
    'Deleted %s' => 'Gelöscht: %s',
    'Could not delete %s' => 'Konnte %s nicht löschen.',
    'File type not allowed.' => 'Dateityp nicht erlaubt.',
    'Upload failed: %s' => 'Hochladen fehlgeschlagen: %s',
    'Upload failed: destination directory not found.' => 'Hochladen fehlgeschlagen: Zielverzeichnis nicht gefunden.',
    'Upload failed: destination directory is not writable.' => 'Hochladen fehlgeschlagen: Zielverzeichnis ist nicht beschreibbar.',
    'Upload failed: move_uploaded_file returned false. Target: %s' => 'Hochladen fehlgeschlagen: move_uploaded_file gab false zurück. Ziel: %s',
    'No file selected.' => 'Keine Datei ausgewählt.',
    'Uploaded %s' => 'Hochgeladen: %s',
    'Manifest saved.' => 'Manifest gespeichert.',
    'Could not save manifest.' => 'Manifest konnte nicht gespeichert werden.',
    'In manifest.json' => 'Im Manifest',
    'Delete' => 'Löschen',
    'Save' => 'Speichern',
    'Move left' => 'Nach links verschieben',
    'Move right' => 'Nach rechts verschieben',
    'Click or tap anywhere to close' => 'Klicken oder tippen, um zu schließen',
    'Delete %s?' => 'Wirklich löschen %s?'
];

function t(string $key, ...$args): string {
    global $TRANSLATIONS;
    $s = $TRANSLATIONS[$key] ?? $key;
    if ($args) {
        return vsprintf($s, $args);
    }
    return $s;
}

// --- HANDLE LOGIN / LOGOUT ---

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ?login');
    exit;
}

if (isset($_GET['login'])) {
    // Show login form (simple)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        if ($user === ADMIN_USERNAME && ADMIN_PASSWORD_HASH !== '' && password_verify($pass, ADMIN_PASSWORD_HASH)) {
            $_SESSION['logged_in'] = true;
            header('Location: ./');
            exit;
        }
        $error = t('Invalid username or password.');
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars(t('News Admin Login'), ENT_QUOTES) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                margin: 0;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                background: #f3f4f6;
            }
            .login-box {
                background: #fff;
                padding: 2rem;
                border-radius: 0.75rem;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                max-width: 320px;
                width: 100%;
            }
            h1 {
                margin-top: 0;
                font-size: 1.4rem;
                margin-bottom: 1rem;
            }
            label {
                display: block;
                margin-top: 0.75rem;
                font-size: 0.9rem;
            }
            input[type="text"],
            input[type="password"] {
                width: 100%;
                padding: 0.5rem;
                margin-top: 0.25rem;
                border-radius: 0.4rem;
                border: 1px solid #d1d5db;
                font-size: 0.95rem;
            }
            button {
                margin-top: 1rem;
                width: 100%;
                padding: 0.6rem;
                border-radius: 0.5rem;
                border: none;
                background: #16a34a;
                color: #fff;
                font-weight: 600;
                cursor: pointer;
            }
            button:hover {
                background: #15803d;
            }
            .error {
                margin-top: 0.75rem;
                color: #b91c1c;
                font-size: 0.85rem;
            }
        </style>
    </head>
    <body>
    <div class="login-box">
        <h1><?= htmlspecialchars(t('News Admin'), ENT_QUOTES) ?></h1>
        <form method="post">
            <label><?= htmlspecialchars(t('Username'), ENT_QUOTES) ?>
                <input type="text" name="username" required>
            </label>
            <label><?= htmlspecialchars(t('Password'), ENT_QUOTES) ?>
                <input type="password" name="password" required>
            </label>
            <button type="submit"><?= htmlspecialchars(t('Login'), ENT_QUOTES) ?></button>
            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
            <?php endif; ?>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// All other routes require login
require_login();

// --- BASIC VARS ---

if (!$NEWS_DIR || !is_dir($NEWS_DIR)) {
    die('NEWS_DIR not found or invalid. Please check config.php.');
}

$imagesOnDisk = array_values(
    array_filter(scandir($NEWS_DIR), function ($file) use ($NEWS_DIR, $ALLOWED_EXTENSIONS) {
        if ($file[0] === '.') return false;
        $path = $NEWS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) return false;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return in_array($ext, $ALLOWED_EXTENSIONS, true);
    })
);

$manifestImages = load_manifest($MANIFEST_FILE);

// Order images: first those in manifest (in manifest order), then the rest
$orderedImages = [];
if (!empty($manifestImages)) {
    foreach ($manifestImages as $m) {
        if (in_array($m, $imagesOnDisk, true)) {
            $orderedImages[] = $m;
        }
    }
}
foreach ($imagesOnDisk as $img) {
    if (!in_array($img, $orderedImages, true)) {
        $orderedImages[] = $img;
    }
}
$displayImages = $orderedImages;

// --- HANDLE ACTIONS (upload, delete, save manifest) ---

$message = null;
$errorMsg = null;

// Delete image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    $file = $_POST['delete_file'] ?? '';
    if ($file && in_array($file, $imagesOnDisk, true)) {
        $fullPath = $NEWS_DIR . DIRECTORY_SEPARATOR . $file;
        if (@unlink($fullPath)) {
            // Remove from manifest as well
            $manifestImages = array_values(array_filter($manifestImages, fn($f) => $f !== $file));
            save_manifest($MANIFEST_FILE, $manifestImages);
            $message = t('Deleted %s', $file);
            // Re-scan images
            $imagesOnDisk = array_values(
                array_filter(scandir($NEWS_DIR), function ($file) use ($NEWS_DIR, $ALLOWED_EXTENSIONS) {
                    if ($file[0] === '.') return false;
                    $path = $NEWS_DIR . DIRECTORY_SEPARATOR . $file;
                    if (!is_file($path)) return false;
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    return in_array($ext, $ALLOWED_EXTENSIONS, true);
                })
            );
        } else {
            $errorMsg = t('Could not delete %s', $file);
        }
    }
}

// Upload image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!empty($_FILES['image']['name'])) {
        // Original uploaded filename
        $origName = basename($_FILES['image']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        // Normalize filename (replace spaces/special chars with underscore)
        $base = pathinfo($origName, PATHINFO_FILENAME);
        // Replace any character that is not a letter, number, underscore or hyphen with underscore.
        // Use Unicode-aware character classes to preserve letters from other languages.
        $base = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $base);
        // Collapse multiple underscores and trim
        $base = preg_replace('/_+/', '_', $base);
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'file';
        }
        // Reconstruct sanitized filename
        $name = $base . ($ext !== '' ? '.' . $ext : '');
        // Avoid overwriting existing files: append -1, -2, ... if needed
        $finalName = $name;
        $i = 1;
        while (file_exists($NEWS_DIR . DIRECTORY_SEPARATOR . $finalName)) {
            $finalName = $base . '-' . $i . ($ext !== '' ? '.' . $ext : '');
            $i++;
        }
        $name = $finalName;
        if (!in_array($ext, $ALLOWED_EXTENSIONS, true)) {
            $errorMsg = t('File type not allowed.');
        } else {
            $target = $NEWS_DIR . DIRECTORY_SEPARATOR . $name;
            // Diagnostics: check PHP upload error codes and dir writability before moving
            $uploadErr = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_OK);
            if ($uploadErr !== UPLOAD_ERR_OK) {
                $errMap = [
                    UPLOAD_ERR_INI_SIZE   => 'Die hochgeladene Datei überschreitet upload_max_filesize in php.ini',
                    UPLOAD_ERR_FORM_SIZE  => 'Die hochgeladene Datei überschreitet die MAX_FILE_SIZE-Vorgabe',
                    UPLOAD_ERR_PARTIAL    => 'Die hochgeladene Datei wurde nur teilweise hochgeladen',
                    UPLOAD_ERR_NO_FILE    => 'Keine Datei hochgeladen',
                    UPLOAD_ERR_NO_TMP_DIR => 'Temporärer Ordner fehlt',
                    UPLOAD_ERR_CANT_WRITE => 'Fehler beim Schreiben der Datei auf die Festplatte',
                    UPLOAD_ERR_EXTENSION  => 'Eine PHP-Erweiterung hat den Upload gestoppt',
                ];
                $reason = $errMap[$uploadErr] ?? ('Unknown upload error code ' . $uploadErr);
                $errorMsg = t('Upload failed: %s', $reason);
                echo '<script>console.error(' . json_encode($errorMsg) . ');</script>';
            } elseif (!is_dir($NEWS_DIR)) {
                $errorMsg = t('Upload failed: destination directory not found.');
                echo '<script>console.error(' . json_encode($errorMsg) . ');</script>';
            } elseif (!is_writable($NEWS_DIR)) {
                $errorMsg = t('Upload failed: destination directory is not writable.');
                echo '<script>console.error(' . json_encode($errorMsg) . ');</script>';
            } elseif (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                // $name is already the final (sanitized + possibly suffixed) filename
                $message = t('Uploaded %s', $name);
                // Reload disk list
                $imagesOnDisk[] = $name;
                $imagesOnDisk = array_values(array_unique($imagesOnDisk));
            } else {
                $errorMsg = t('Upload failed: move_uploaded_file returned false. Target: %s', $target);
                echo '<script>console.error(' . json_encode($errorMsg) . ');</script>';
            }
        }
    } else {
        $errorMsg = t('No file selected.');
        echo '<script>console.warn(' . json_encode($errorMsg) . ');</script>';
    }
}

// Save manifest (checkboxes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manifest'])) {
    $checked = $_POST['in_manifest'] ?? [];
    $checked = array_values(array_filter($checked, fn($x) => in_array($x, $imagesOnDisk, true)));
    if (save_manifest($MANIFEST_FILE, $checked)) {
        $manifestImages = $checked;
        $message = t('Manifest saved.');
    } else {
        $errorMsg = t('Could not save manifest.');
    }
}

// Rebuild display order after any changes above
$orderedImages = [];
if (!empty($manifestImages)) {
    foreach ($manifestImages as $m) {
        if (in_array($m, $imagesOnDisk, true)) {
            $orderedImages[] = $m;
        }
    }
}
foreach ($imagesOnDisk as $img) {
    if (!in_array($img, $orderedImages, true)) {
        $orderedImages[] = $img;
    }
}
$displayImages = $orderedImages;

// Helper: is in manifest
function in_manifest(string $file, array $manifest): bool {
    return in_array($file, $manifest, true);
}


?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>News Admin – Erlebnishof Auszeit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #f9fafb;
            --card: #ffffff;
            --border: #e5e7eb;
            --accent: #16a34a;
            --accent-soft: #bbf7d0;
            --danger: #dc2626;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: #111827;
        }
        header {
            padding: 1rem 1.5rem;
            background: #111827;
            color: #f9fafb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 {
            margin: 0;
            font-size: 1.1rem;
        }
        header a.logout {
            color: #f9fafb;
            text-decoration: none;
            font-size: 0.9rem;
            border: 1px solid rgba(249,250,251,0.4);
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
        }
        header a.logout:hover {
            background: rgba(249,250,251,0.12);
        }
        main {
            max-width: 1100px;
            margin: 1.5rem auto;
            padding: 0 1rem 1.5rem;
        }
        .card {
            background: var(--card);
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        h2 {
            margin: 0 0 0.75rem;
            font-size: 1rem;
        }
        .status {
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .status .ok {
            color: #166534;
            background: var(--accent-soft);
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
        }
        .status .err {
            color: #b91c1c;
            background: #fee2e2;
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
        }
        .upload-form input[type="file"] {
            font-size: 0.9rem;
        }
        .upload-form button,
        .save-btn {
            margin-top: 0.5rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            border: none;
            background: var(--accent);
            color: #fff;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
        }
        .save-btn {
            margin-top: 0.75rem;
        }
        .upload-form button:hover,
        .save-btn:hover {
            background: #15803d;
        }
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem;
        }
        .img-card {
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 0.5rem;
            background: #fefefe;
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }
        .thumb-wrapper {
            position: relative;
            overflow: hidden;
            border-radius: 0.5rem;
            cursor: zoom-in;
        }
        .thumb-wrapper img {
            width: 100%;
            height: 110px;
            object-fit: cover;
            display: block;
        }
        .filename {
            font-size: 0.8rem;
            word-break: break-all;
        }
        .move-controls {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }
        .move-btn {
            width: 44px;
            height: 44px;
            border-radius: 0.5rem;
            border: 1px solid var(--border);
            background: #fff;
            font-size: 1.1rem;
            cursor: pointer;
        }
        .move-btn:hover {
            background: #f3f4f6;
        }
        .move-btn:disabled,
        .move-btn[aria-disabled="true"] {
            opacity: 0.45;
            cursor: not-allowed;
            background: #f9fafb;
        }
        .manifest-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        .manifest-row label {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            cursor: pointer;
        }
        .manifest-row input[type="checkbox"] {
            transform: scale(1.1);
        }
        .delete-btn {
            background: none;
            border: none;
            color: var(--danger);
            font-size: 0.8rem;
            cursor: pointer;
            padding: 0.15rem 0.4rem;
            border-radius: 999px;
            border: 1px solid #fecaca;
        }
        .delete-btn:hover {
            background: #fee2e2;
        }

        /* Fullscreen overlay */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.85);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }
        .overlay img {
            max-width: 95vw;
            max-height: 95vh;
            border-radius: 0.75rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.5);
        }
        .overlay-close {
            position: fixed;
            top: 10px;
            right: 18px;
            color: #e5e7eb;
            font-size: 2rem;
            cursor: pointer;
        }
        .overlay-hint {
            position: fixed;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            color: #e5e7eb;
            font-size: 0.8rem;
            text-align: center;
        }
    </style>
</head>
<body>
<header>
    <h1>News Admin – Erlebnishof Auszeit</h1>
    <a class="logout" href="?logout"><?= htmlspecialchars(t('Logout'), ENT_QUOTES) ?></a>
</header>
<main>
    <div class="card">
        <h2><?= htmlspecialchars(t('Upload new image'), ENT_QUOTES) ?></h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="action" value="upload">
            <input id="imageInput" type="file" name="image" accept="image/*" required>
            <br>
            <!-- always enabled; clicking it will open picker if nothing selected -->
            <button id="uploadBtn" type="submit"><?= htmlspecialchars(t('Upload'), ENT_QUOTES) ?></button>
        </form>
    </div>

    <div class="card">
        <h2><?= t('Images in <code>news</code> folder') ?></h2>
        <div class="status">
            <?php if ($message): ?>
                <span class="ok"><?= htmlspecialchars($message, ENT_QUOTES) ?></span>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
                <span class="err"><?= htmlspecialchars($errorMsg, ENT_QUOTES) ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($imagesOnDisk)): ?>
            <p><?= t('No images found in the <code>news</code> folder.') ?></p>
        <?php else: ?>
            <form method="post">
                <div class="images-grid">
                    <?php foreach ($displayImages as $file): ?>
                        <?php
                        $isInManifest = in_manifest($file, $manifestImages);
                        // Serve images via local proxy so the browser loads them from /admin
                        // even when the real images live outside the document root.
                        $src = 'img.php?file=' . rawurlencode($file);
                        ?>
                        <div class="img-card" draggable="true" tabindex="0">
                            <div class="thumb-wrapper" data-full="<?= htmlspecialchars($src, ENT_QUOTES) ?>">
                                <img src="<?= htmlspecialchars($src, ENT_QUOTES) ?>" alt="">
                            </div>
                            <div class="filename"><?= htmlspecialchars($file, ENT_QUOTES) ?></div>
                            <div class="move-controls">
                                <button type="button" class="move-btn" data-direction="left" aria-label="<?= htmlspecialchars(t('Move left'), ENT_QUOTES) ?>">&larr;</button>
                                <button type="button" class="move-btn" data-direction="right" aria-label="<?= htmlspecialchars(t('Move right'), ENT_QUOTES) ?>">&rarr;</button>
                            </div>
                            <div class="manifest-row">
                                <label>
                                    <input
                                        type="checkbox"
                                        name="in_manifest[]"
                                        value="<?= htmlspecialchars($file, ENT_QUOTES) ?>"
                                        <?= $isInManifest ? 'checked' : '' ?>
                                    >
                                    <?= htmlspecialchars(t('In manifest.json'), ENT_QUOTES) ?>
                                </label>
                                <button type="submit" class="delete-btn"
                                        name="delete_file"
                                        value="<?= htmlspecialchars($file, ENT_QUOTES) ?>"
                                        onclick="return confirm('<?= htmlspecialchars(t('Delete %s?', $file), ENT_QUOTES) ?>');">
                                    <?= htmlspecialchars(t('Delete'), ENT_QUOTES) ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="save-btn" name="save_manifest" value="1"><?= htmlspecialchars(t('Save'), ENT_QUOTES) ?></button>
            </form>
        <?php endif; ?>
    </div>
</main>

<!-- Fullscreen overlay -->
<div class="overlay" id="overlay">
    <div class="overlay-close" id="overlayClose">&times;</div>
    <img id="overlayImg" src="" alt="">
    <div class="overlay-hint"><?= htmlspecialchars(t('Click or tap anywhere to close'), ENT_QUOTES) ?></div>
</div>

<script>
    // Fullscreen preview on click or long-press
    const overlay = document.getElementById('overlay');
    const overlayImg = document.getElementById('overlayImg');
    const overlayClose = document.getElementById('overlayClose');

    function openOverlay(src) {
        overlayImg.src = src;
        overlay.style.display = 'flex';
    }

    function closeOverlay() {
        overlay.style.display = 'none';
        overlayImg.src = '';
    }

    overlay.addEventListener('click', closeOverlay);
    overlayClose.addEventListener('click', closeOverlay);

    const thumbs = document.querySelectorAll('.thumb-wrapper');
    thumbs.forEach(t => {
        const src = t.getAttribute('data-full');
        let pressTimer;

        // Click = open
        t.addEventListener('click', () => openOverlay(src));

        // Long-press (touch)
        t.addEventListener('touchstart', (e) => {
            pressTimer = setTimeout(() => openOverlay(src), 400);
        }, {passive: true});
        t.addEventListener('touchend', () => clearTimeout(pressTimer));
        t.addEventListener('touchmove', () => clearTimeout(pressTimer));

        // Long-press (mouse)
        t.addEventListener('mousedown', () => {
            pressTimer = setTimeout(() => openOverlay(src), 400);
        });
        t.addEventListener('mouseup', () => clearTimeout(pressTimer));
        t.addEventListener('mouseleave', () => clearTimeout(pressTimer));
    });

    // --- Open file picker when upload clicked and no file selected ---
    (function () {
        const fileInput = document.getElementById('imageInput');
        const uploadBtn = document.getElementById('uploadBtn');
        const uploadForm = document.getElementById('uploadForm');
        if (!fileInput || !uploadBtn || !uploadForm) return;

        // If user clicks Upload but no file chosen, open the file picker instead of submitting.
        uploadBtn.addEventListener('click', function (e) {
            if (!(fileInput.files && fileInput.files.length > 0)) {
                e.preventDefault(); // prevent form submit
                // Open native file picker
                fileInput.click();
            }
            // if a file is selected, allow normal submit
        });

        // Optional: after user selects a file, you can auto-submit the form.
        // Uncomment the following line if you want to auto-submit immediately after selection:
        // fileInput.addEventListener('change', () => { if (fileInput.files.length) uploadForm.submit(); });
    })();

    // --- Drag-and-drop reordering of cards and checkbox behavior ---
    const grid = document.querySelector('.images-grid');
    if (grid) {
        // Helper: is a card selected (its checkbox checked)?
        const isCardSelected = (card) => !!card?.querySelector('.manifest-row input[type="checkbox"]').checked;

        // Helper: find previous/next selected sibling card
        const findPrevSelected = (card) => {
            let p = card.previousElementSibling;
            while (p) {
                if (isCardSelected(p)) return p;
                p = p.previousElementSibling;
            }
            return null;
        };
        const findNextSelected = (card) => {
            let n = card.nextElementSibling;
            while (n) {
                if (isCardSelected(n)) return n;
                n = n.nextElementSibling;
            }
            return null;
        };

        grid.addEventListener('dragstart', (e) => {
            const card = e.target.closest('.img-card');
            if (!card) return;
            card.classList.add('dragging');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', '');
            }
        });

        grid.addEventListener('dragend', (e) => {
            const card = e.target.closest('.img-card');
            if (!card) return;
            card.classList.remove('dragging');
        });

        grid.addEventListener('dragover', (e) => {
            e.preventDefault();
            const dragging = grid.querySelector('.img-card.dragging');
            if (!dragging) return;
            const targetCard = e.target.closest('.img-card');
            if (!targetCard || targetCard === dragging) return;
            const rect = targetCard.getBoundingClientRect();
            const before = e.clientY < rect.top + rect.height / 2;
            if (before) {
                grid.insertBefore(dragging, targetCard);
            } else {
                grid.insertBefore(dragging, targetCard.nextElementSibling);
            }
        });

        // Enable/disable move buttons based on selection
        const refreshMoveButtons = () => {
            grid.querySelectorAll('.img-card').forEach(card => {
                const selected = isCardSelected(card);
                card.querySelectorAll('.move-btn').forEach(btn => {
                    btn.disabled = !selected;
                    btn.setAttribute('aria-disabled', String(!selected));
                    btn.tabIndex = selected ? 0 : -1;
                });
            });
        };

        // Initial state
        refreshMoveButtons();

        grid.querySelectorAll('.move-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.img-card');
                if (!card) return;
                // Only allow moving selected cards
                if (!isCardSelected(card)) return;
                const direction = btn.dataset.direction;
                if (direction === 'left') {
                    const prev = findPrevSelected(card);
                    if (prev) grid.insertBefore(card, prev);
                } else if (direction === 'right') {
                    const next = findNextSelected(card);
                    if (next) grid.insertBefore(card, next.nextElementSibling);
                }
            });
        });

        // When a checkbox is checked, move its card to the end of the selected group
        grid.querySelectorAll('.manifest-row input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', () => {
                const card = cb.closest('.img-card');
                if (!card) return;

                if (cb.checked) {
                    const cards = Array.from(grid.querySelectorAll('.img-card'));
                    const lastSelected = cards
                        .filter(c => c.querySelector('.manifest-row input[type="checkbox"]').checked && c !== card)
                        .pop();

                    if (lastSelected) {
                        lastSelected.after(card);
                    } else {
                        grid.prepend(card);
                    }
                }
                // Update move buttons availability
                refreshMoveButtons();
                // If unchecked, card remains where it is; PHP will drop it from manifest on Save.
            });
        });

        // Keyboard support: allow Left/Right to move only selected (focused) card among selected group
        grid.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            const focused = document.activeElement?.closest?.('.img-card');
            if (!focused || !grid.contains(focused)) return;
            if (!isCardSelected(focused)) return; // only for selected
            e.preventDefault();
            if (e.key === 'ArrowLeft') {
                const prev = findPrevSelected(focused);
                if (prev) grid.insertBefore(focused, prev);
            } else if (e.key === 'ArrowRight') {
                const next = findNextSelected(focused);
                if (next) grid.insertBefore(focused, next.nextElementSibling);
            }
        });
    }
</script>
</body>
</html>
