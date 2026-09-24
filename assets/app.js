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

  const paymentLayout = document.querySelector('.payment-method-layout');
  const paymentResizer = paymentLayout?.querySelector('[data-payment-resizer]');
  if (paymentLayout && paymentResizer) {
    const desktop = window.matchMedia('(min-width: 1281px)');
    let ratio = Math.min(.6, Math.max(.28, Number(localStorage.getItem('paymentColumnRatioV2')) || .43));
    const applyPaymentColumns = () => {
      if (!desktop.matches) {
        paymentLayout.style.removeProperty('grid-template-columns');
        return;
      }
      const available = paymentLayout.clientWidth - 32;
      const minLeft = Math.min(320, available * .45);
      const minRight = Math.min(360, available * .5);
      const left = Math.max(minLeft, Math.min(available - minRight, available * ratio));
      paymentLayout.style.gridTemplateColumns = `${left}px 12px minmax(360px, 1fr)`;
    };
    const setRatioFromPointer = clientX => {
      const rect = paymentLayout.getBoundingClientRect();
      const available = rect.width - 32;
      ratio = Math.min(.6, Math.max(.28, (clientX - rect.left) / available));
      applyPaymentColumns();
    };
    paymentResizer.addEventListener('pointerdown', event => {
      if (!desktop.matches) return;
      paymentResizer.setPointerCapture(event.pointerId);
      paymentLayout.classList.add('is-resizing');
      setRatioFromPointer(event.clientX);
    });
    paymentResizer.addEventListener('pointermove', event => {
      if (!paymentResizer.hasPointerCapture(event.pointerId)) return;
      setRatioFromPointer(event.clientX);
    });
    const finishResize = event => {
      if (paymentResizer.hasPointerCapture(event.pointerId)) paymentResizer.releasePointerCapture(event.pointerId);
      paymentLayout.classList.remove('is-resizing');
      localStorage.setItem('paymentColumnRatioV2', ratio.toFixed(3));
    };
    paymentResizer.addEventListener('pointerup', finishResize);
    paymentResizer.addEventListener('pointercancel', finishResize);
    paymentResizer.addEventListener('keydown', event => {
      if (!desktop.matches || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      if (event.key === 'Home') ratio = .28;
      else if (event.key === 'End') ratio = .6;
      else ratio = Math.min(.6, Math.max(.28, ratio + (event.key === 'ArrowRight' ? .03 : -.03)));
      localStorage.setItem('paymentColumnRatioV2', ratio.toFixed(3));
      applyPaymentColumns();
    });
    window.addEventListener('resize', applyPaymentColumns);
    applyPaymentColumns();
  }

  const collectionForm = document.querySelector('[data-collection-form]');
  if (collectionForm) {
    const amount = collectionForm.querySelector('[data-collection-amount]');
    const types = [...collectionForm.querySelectorAll('input[name="collection_type"]')];
    const remaining = collectionForm.dataset.remaining || '';
    let partialAmount = amount?.value || '';
    const refreshCollectionType = () => {
      if (!amount) return;
      const full = collectionForm.elements.collection_type.value === 'full';
      if (full) {
        if (amount.value !== remaining) partialAmount = amount.value;
        amount.value = remaining;
        amount.readOnly = true;
      } else {
        amount.readOnly = false;
        amount.value = partialAmount === remaining ? '' : partialAmount;
      }
    };
    amount?.addEventListener('input', () => {
      if (!amount.readOnly) partialAmount = amount.value;
    });
    types.forEach(input => input.addEventListener('change', refreshCollectionType));
    refreshCollectionType();
  }

  const form = document.getElementById('invoice-form');
  if (!form) return;

  const clientLookupInputs = [...form.querySelectorAll('[data-client-lookup]')];
  const clientResults = form.querySelector('[data-client-results]');
  const clientMatch = form.querySelector('[data-client-match]');
  const matchedClientId = form.elements.matched_client_id;
  if (clientLookupInputs.length && clientResults && clientMatch && form.dataset.clientSearchUrl) {
    let searchTimer = 0;
    let searchController;
    const normalizePhone = value => {
      let digits = value.replace(/\D/g, '');
      if (digits.startsWith('880')) digits = `0${digits.slice(3)}`;
      return digits;
    };
    const hideResults = () => {
      clientResults.hidden = true;
      clientResults.replaceChildren();
    };
    const selectClient = client => {
      form.elements.client_phone.value = client.phone || '';
      form.elements.client_email.value = client.email || '';
      form.elements.client_name.value = client.name || '';
      form.elements.company_name.value = client.company_name || '';
      matchedClientId.value = client.id || '';
      clientMatch.textContent = `বিদ্যমান ক্লায়েন্ট পাওয়া গেছে: ${client.name}${client.company_name ? ` · ${client.company_name}` : ''}`;
      clientMatch.hidden = false;
      hideResults();
    };
    const renderClients = (clients, source, query) => {
      hideResults();
      const normalizedQuery = source.name === 'client_phone' ? normalizePhone(query) : query.trim().toLowerCase();
      const exact = clients.find(client => source.name === 'client_phone'
        ? normalizePhone(client.phone || '') === normalizedQuery
        : (client.email || '').trim().toLowerCase() === normalizedQuery);
      if (exact) {
        selectClient(exact);
        return;
      }
      if (!clients.length) {
        const empty = document.createElement('div');
        empty.className = 'client-search-empty';
        empty.textContent = 'কোনো বিদ্যমান ক্লায়েন্ট পাওয়া যায়নি। এই তথ্য দিয়ে নতুন ক্লায়েন্ট তৈরি হবে।';
        clientResults.append(empty);
      } else {
        clients.forEach(client => {
          const option = document.createElement('button');
          option.type = 'button';
          option.className = 'client-search-option';
          const identity = document.createElement('span');
          const name = document.createElement('strong');
          const company = document.createElement('small');
          name.textContent = client.name;
          company.textContent = client.company_name || 'কোম্পানির নাম নেই';
          identity.append(name, company);
          const contact = document.createElement('span');
          contact.className = 'client-search-contact';
          contact.textContent = `${client.phone}${client.email ? ` · ${client.email}` : ''}`;
          option.append(identity, contact);
          option.addEventListener('click', () => selectClient(client));
          clientResults.append(option);
        });
      }
      clientResults.hidden = false;
    };
    clientLookupInputs.forEach(input => input.addEventListener('input', () => {
      matchedClientId.value = '';
      clientMatch.hidden = true;
      const query = input.value.trim();
      const searchable = input.name === 'client_phone' ? normalizePhone(query).length >= 3 : query.length >= 2;
      clearTimeout(searchTimer);
      searchController?.abort();
      if (!searchable) {
        hideResults();
        return;
      }
      searchTimer = window.setTimeout(async () => {
        searchController = new AbortController();
        const endpoint = new URL(form.dataset.clientSearchUrl, window.location.href);
        endpoint.searchParams.set('q', query);
        try {
          const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, signal: searchController.signal });
          if (!response.ok) throw new Error('Client search failed');
          renderClients(await response.json(), input, query);
        } catch (error) {
          if (error.name !== 'AbortError') hideResults();
        }
      }, 220);
    }));
    document.addEventListener('click', event => {
      if (!event.target.closest('.client-fields')) hideResults();
    });
  }

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
