(() => {
  const sidebar = document.getElementById('sidebar');
  document.querySelector('[data-menu-toggle]')?.addEventListener('click', () => {
    sidebar?.classList.toggle('open');
    document.querySelector('.mobile-backdrop')?.classList.toggle('open');
  });
  document.querySelector('[data-close-menu]')?.addEventListener('click', () => {
    sidebar?.classList.remove('open');
    document.querySelector('.mobile-backdrop')?.classList.remove('open');
  });

  document.querySelectorAll('[data-preview-target]').forEach(input => {
    const preview = document.getElementById(input.dataset.previewTarget);
    const placeholder = preview?.parentElement.querySelector('.upload-placeholder');
    const remove = document.querySelector(`[data-remove-image="${input.dataset.previewTarget}"]`);
    if (!preview || !placeholder) return;
    const originalSrc = preview.getAttribute('src') || '';
    let objectUrl = '';
    const show = src => {
      if (src) preview.src = src;
      else preview.removeAttribute('src');
      preview.hidden = !src;
      placeholder.hidden = !!src;
    };
    input.addEventListener('change', () => {
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = '';
      const file = input.files?.[0];
      if (file) {
        objectUrl = URL.createObjectURL(file);
        show(objectUrl);
        if (remove) remove.checked = false;
      } else show(remove?.checked ? '' : originalSrc);
    });
    remove?.addEventListener('change', () => {
      if (remove.checked) {
        input.value = '';
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = '';
        show('');
      } else show(originalSrc);
    });
    window.addEventListener('beforeunload', () => { if (objectUrl) URL.revokeObjectURL(objectUrl); });
  });

  const form = document.getElementById('invoice-form');
  if (!form) return;

  const itemContainer = document.getElementById('invoice-items');
  const template = document.getElementById('item-template');
  const currency = new Intl.NumberFormat('en-BD', { maximumFractionDigits: 2 });

  const refresh = () => {
    const items = [...itemContainer.querySelectorAll('.line-item')];
    let total = 0;
    items.forEach((item, index) => {
      item.querySelector('.item-index').textContent = `আইটেম ${index + 1}`;
      total += (parseFloat(item.querySelector('.item-qty').value) || 0) *
        (parseFloat(item.querySelector('.item-price').value) || 0);
    });
    document.getElementById('summary-count').textContent = items.length;
    document.getElementById('summary-total').textContent = `৳${currency.format(total)}`;
    document.querySelectorAll('.recurring-field').forEach(el => {
      el.hidden = form.elements.invoice_type.value !== 'recurring';
    });
  };

  document.getElementById('add-item')?.addEventListener('click', () => {
    if (itemContainer.querySelectorAll('.line-item').length >= 30) return;
    itemContainer.append(template.content.cloneNode(true));
    refresh();
    itemContainer.lastElementChild.querySelector('.service-select')?.focus();
  });

  itemContainer.addEventListener('click', event => {
    if (!event.target.closest('.remove-item')) return;
    if (itemContainer.querySelectorAll('.line-item').length <= 1) return;
    event.target.closest('.line-item').remove();
    refresh();
  });

  itemContainer.addEventListener('change', event => {
    if (!event.target.matches('.service-select')) return;
    const item = event.target.closest('.line-item');
    const option = event.target.selectedOptions[0];
    if (option.value) {
      item.querySelector('.item-name').value = option.dataset.name || '';
      item.querySelector('.item-description').value = option.dataset.description || '';
      item.querySelector('.item-price').value = option.dataset.price || '';
    }
    refresh();
  });
  itemContainer.addEventListener('input', refresh);
  form.querySelectorAll('input[name="invoice_type"]').forEach(input => input.addEventListener('change', refresh));
  refresh();
})();
