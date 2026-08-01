(() => {
  'use strict';

  const entryType = document.querySelector('[data-entry-type]');
  const syncEntryFields = () => {
    if (!entryType) return;
    const event = entryType.value === 'event';
    document.querySelectorAll('[data-event-field]').forEach((field) => {
      field.hidden = !event;
      const input = field.querySelector('input');
      if (input && input.name === 'eventStart') input.required = event;
    });
    document.querySelectorAll('[data-news-field]').forEach((field) => { field.hidden = event; });
  };
  entryType?.addEventListener('change', syncEntryFields);
  syncEntryFields();

  const entryIntent = document.querySelector('[data-entry-intent]');
  const entryImage = document.querySelector('[data-entry-image]');
  const syncApprovalRequirements = () => {
    if (!entryIntent) return;
    const approved = entryIntent.value === 'approved';
    const title = document.querySelector('[name="title"]');
    const body = document.querySelector('[name="body"]');
    const alt = document.querySelector('[data-entry-image-alt]');
    if (title) title.required = approved;
    if (body) body.required = approved;
    if (alt) alt.required = approved && Boolean(entryImage?.value);
  };
  entryIntent?.addEventListener('change', syncApprovalRequirements);
  entryImage?.addEventListener('change', syncApprovalRequirements);
  syncApprovalRequirements();

  const previewTitleSource = document.querySelector('[data-preview-title-source]');
  const previewBodySource = document.querySelector('[data-preview-body-source]');
  const syncPreview = () => {
    const title = document.querySelector('[data-preview-title]');
    const body = document.querySelector('[data-preview-body]');
    if (title && previewTitleSource) title.textContent = previewTitleSource.value.trim() || 'Titel';
    if (body && previewBodySource) body.textContent = previewBodySource.value.trim() || 'Der Text erscheint hier als reiner Text.';
  };
  previewTitleSource?.addEventListener('input', syncPreview);
  previewBodySource?.addEventListener('input', syncPreview);

  const syncReplacementHours = () => {
    const selected = document.querySelector('[data-closed-choice]:checked');
    const wrapper = document.querySelector('[data-replacement-hours]');
    if (!selected || !wrapper) return;
    const closed = selected.value === '1';
    wrapper.hidden = closed;
    wrapper.querySelectorAll('input').forEach((input) => {
      input.disabled = closed;
      input.required = !closed;
    });
  };
  document.querySelectorAll('[data-closed-choice]').forEach((choice) => choice.addEventListener('change', syncReplacementHours));
  syncReplacementHours();

  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!window.confirm(button.dataset.confirm || 'Aktion wirklich ausführen?')) event.preventDefault();
    });
  });

  let dirty = false;
  document.querySelectorAll('[data-dirty-form]').forEach((form) => {
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
  });
  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });

  document.querySelector('#error-summary')?.focus();
})();
