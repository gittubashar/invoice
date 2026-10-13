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

  const multiCollection = document.querySelector('[data-multi-collection]');
  if (multiCollection) {
    const workspace = document.querySelector('[data-collection-workspace]');
    const boxes = [...document.querySelectorAll('input[name="invoice_ids[]"][form="bulk-collection-form"]')];
    const selectAll = workspace?.querySelector('[data-select-all]');
    const counts = [...document.querySelectorAll('[data-selected-count]')];
    const totals = [...document.querySelectorAll('[data-selected-total]')];
    const refreshSelected = () => {
      const selected = boxes.filter(box => box.checked);
      const cents = selected.reduce((sum, box) => sum + Number(box.dataset.balance || 0), 0);
      counts.forEach(count => { count.textContent = `${selected.length} selected`; });
      totals.forEach(total => { total.textContent = `BDT ${new Intl.NumberFormat('en-BD', { maximumFractionDigits: 2 }).format(cents / 100)}`; });
      if (selectAll) selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
    };
    boxes.forEach(box => box.addEventListener('change', refreshSelected));
    selectAll?.addEventListener('change', () => { boxes.forEach(box => { box.checked = selectAll.checked; }); refreshSelected(); });
    refreshSelected();
  }

  const collectionClientSearch = document.querySelector('[data-collection-client-search]');
  if (collectionClientSearch) {
    const input = collectionClientSearch.querySelector('[data-collection-client-input]');
    const results = document.querySelector('[data-collection-client-results]');
    let timer = 0;
    let controller;
    const hideResults = () => {
      if (!results) return;
      results.hidden = true;
      results.replaceChildren();
    };
    const clientUrl = client => {
      const target = new URL(collectionClientSearch.dataset.selectUrl, window.location.href);
      target.searchParams.set('client_id', client.id);
      target.searchParams.set('q', input.value.trim());
      return target.toString();
    };
    const renderClients = clients => {
      if (!results) return;
      results.replaceChildren();
      if (!clients.length) {
        const empty = document.createElement('div');
        empty.className = 'collection-client-empty';
        empty.textContent = 'No matching client was found.';
        results.append(empty);
      } else {
        clients.forEach(client => {
          const link = document.createElement('a');
          link.href = clientUrl(client);
          const avatar = document.createElement('span');
          avatar.className = 'client-avatar';
          avatar.textContent = client.name.slice(0, 1).toUpperCase();
          const identity = document.createElement('span');
          const name = document.createElement('strong');
          const company = document.createElement('small');
          name.textContent = client.name;
          company.textContent = client.company_name || 'No company name';
          identity.append(name, company);
          const contact = document.createElement('span');
          contact.className = 'collection-client-contact';
          contact.append(document.createTextNode(client.phone));
          if (client.email) {
            const email = document.createElement('small');
            email.textContent = client.email;
            contact.append(email);
          }
          const arrow = document.createElement('span');
          arrow.className = 'collection-live-arrow';
          arrow.textContent = '›';
          link.append(avatar, identity, contact, arrow);
          results.append(link);
        });
      }
      results.hidden = false;
    };
    const searchClients = async () => {
      const query = input?.value.trim() || '';
      if (query.length < 2) {
        controller?.abort();
        hideResults();
        return;
      }
      controller?.abort();
      controller = new AbortController();
      const endpoint = new URL(collectionClientSearch.dataset.searchUrl, window.location.href);
      endpoint.searchParams.set('q', query);
      try {
        const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, signal: controller.signal });
        if (!response.ok) throw new Error('Search failed');
        renderClients(await response.json());
      } catch (error) {
        if (error.name !== 'AbortError') hideResults();
      }
    };
    input?.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(searchClients, 250);
    });
    input?.addEventListener('focus', () => {
      if (input.value.trim().length >= 2) searchClients();
    });
    document.addEventListener('click', event => {
      if (!collectionClientSearch.contains(event.target) && !results?.contains(event.target)) hideResults();
    });
  }

  document.querySelectorAll('.recurring-discount-form').forEach(discountForm => {
    const enabled = discountForm.elements.discount_enabled;
    const scope = discountForm.elements.discount_scope;
    const cycleControl = discountForm.querySelector('.discount-cycle-control');
    const refreshScheduleDiscount = () => {
      discountForm.classList.toggle('discount-disabled', !enabled.checked);
      if (cycleControl) cycleControl.hidden = scope.value !== 'limited';
    };
    enabled.addEventListener('change', refreshScheduleDiscount);
    scope.addEventListener('change', refreshScheduleDiscount);
    refreshScheduleDiscount();
  });

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
      clientMatch.textContent = `Existing client found: ${client.name}${client.company_name ? ` · ${client.company_name}` : ''}`;
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
        empty.textContent = 'No existing client was found. A new client will be created with this information.';
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
          company.textContent = client.company_name || 'No company name';
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
  const discountEnabled = form.querySelector('[data-discount-enabled]');
  const discountFields = form.querySelector('[data-discount-fields]');
  const discountScope = form.querySelector('[data-discount-scope]');
  const discountCycles = form.querySelector('[data-discount-cycles]');
  const initialDiscountEnabled = form.querySelector('[data-initial-discount-enabled]');
  const initialDiscountFields = form.querySelector('[data-initial-discount-fields]');
  const catalogNode = document.getElementById('service-catalog');
  let serviceCatalog = [];
  try {
    serviceCatalog = catalogNode ? JSON.parse(catalogNode.textContent) : [];
  } catch (error) {
    serviceCatalog = [];
  }

  const closeServiceSuggestions = except => {
    itemContainer.querySelectorAll('.invoice-service-suggestions').forEach(list => {
      if (list !== except) {
        list.hidden = true;
        list.replaceChildren();
      }
    });
  };

  const applyService = (item, service) => {
    item.querySelector('.item-search').value = service.name || '';
    item.querySelector('.service-id').value = service.id || '';
    item.querySelector('.item-name').value = service.name || '';
    item.querySelector('.item-description').value = service.description || '';
    item.querySelector('.item-price').value = service.price || '';
    closeServiceSuggestions();
    refresh();
    item.querySelector('.item-qty').focus();
    item.querySelector('.item-qty').select();
  };

  const renderServiceSuggestions = input => {
    const item = input.closest('.line-item');
    const list = item?.querySelector('.invoice-service-suggestions');
    if (!item || !list) return;
    const query = input.value.trim().toLocaleLowerCase();
    closeServiceSuggestions(list);
    list.replaceChildren();
    if (!query) {
      list.hidden = true;
      return;
    }
    const matches = serviceCatalog.filter(service => `${service.name} ${service.description || ''}`.toLocaleLowerCase().includes(query)).slice(0, 6);
    matches.forEach((service, index) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.className = `invoice-service-option${index === 0 ? ' active' : ''}`;
      option.dataset.serviceId = String(service.id);
      const copy = document.createElement('span');
      const name = document.createElement('strong');
      const description = document.createElement('small');
      const price = document.createElement('b');
      name.textContent = service.name;
      description.textContent = service.description || 'Saved service';
      price.textContent = `BDT ${currency.format(parseFloat(service.price) || 0)}`;
      copy.append(name, description);
      option.append(copy, price);
      list.append(option);
    });
    if (!matches.length) {
      const custom = document.createElement('div');
      custom.className = 'invoice-service-custom';
      custom.textContent = `Press Enter to use “${input.value.trim()}” as a custom description.`;
      list.append(custom);
    }
    list.hidden = false;
  };

  const appendInvoiceLine = () => {
    if (itemContainer.querySelectorAll('.line-item').length >= 30) return null;
    itemContainer.append(template.content.cloneNode(true));
    refresh();
    return itemContainer.lastElementChild;
  };

  const focusNextInvoiceLine = item => {
    const rows = [...itemContainer.querySelectorAll('.line-item')];
    const index = rows.indexOf(item);
    const next = rows[index + 1] || appendInvoiceLine();
    next?.querySelector('.item-search, .service-select')?.focus();
  };

  const refresh = () => {
    const items = [...itemContainer.querySelectorAll('.line-item')];
    let total = 0;
    let filledItems = 0;
    items.forEach((item, index) => {
      item.querySelector('.item-index').textContent = item.classList.contains('invoice-entry-row') ? String(index + 1) : `Item ${index + 1}`;
      const qty = parseFloat(item.querySelector('.item-qty').value) || 0;
      const price = parseFloat(item.querySelector('.item-price').value) || 0;
      const lineTotal = qty * price;
      const hasItem = Boolean(item.querySelector('.item-name')?.value.trim() || item.querySelector('.item-search')?.value.trim());
      if (hasItem) filledItems += 1;
      total += lineTotal;
      const lineTotalNode = item.querySelector('.item-line-total');
      if (lineTotalNode) lineTotalNode.textContent = `BDT ${currency.format(lineTotal)}`;
    });
    document.getElementById('summary-count').textContent = filledItems;
    const recurring = form.elements.invoice_type.value === 'recurring';
    const editing = form.elements.action.value === 'edit_invoice';
    const initialDiscountActive = !editing && Boolean(initialDiscountEnabled?.checked);
    const recurringDiscountActive = (recurring || editing) && Boolean(discountEnabled?.checked);
    const discountActive = initialDiscountActive || recurringDiscountActive;
    const discountType = initialDiscountActive ? form.elements.initial_discount_type?.value : form.elements.discount_type?.value;
    const rawDiscount = parseFloat(initialDiscountActive ? form.elements.initial_discount_value?.value : form.elements.discount_value?.value) || 0;
    const discount = discountActive
      ? Math.min(total, discountType === 'percent' ? total * Math.min(rawDiscount, 100) / 100 : rawDiscount)
      : 0;
    if (document.getElementById('summary-subtotal')) document.getElementById('summary-subtotal').textContent = `BDT ${currency.format(total)}`;
    if (document.getElementById('summary-discount')) document.getElementById('summary-discount').textContent = `BDT ${currency.format(discount)}`;
    if (document.getElementById('summary-discount-row')) document.getElementById('summary-discount-row').hidden = !discountActive;
    document.getElementById('summary-total').textContent = `BDT ${currency.format(Math.max(0, total - discount))}`;
    document.querySelectorAll('.recurring-field').forEach(el => {
      el.hidden = !recurring;
    });
    if (discountFields) discountFields.hidden = !recurringDiscountActive;
    if (discountCycles) discountCycles.hidden = !recurringDiscountActive || discountScope?.value !== 'limited';
    if (initialDiscountFields) initialDiscountFields.hidden = !initialDiscountActive;
  };

  document.getElementById('add-item')?.addEventListener('click', () => {
    const item = appendInvoiceLine();
    item?.querySelector('.item-search, .service-select')?.focus();
  });

  itemContainer.addEventListener('click', event => {
    const serviceOption = event.target.closest('.invoice-service-option');
    if (serviceOption) {
      const item = serviceOption.closest('.line-item');
      const service = serviceCatalog.find(entry => String(entry.id) === serviceOption.dataset.serviceId);
      if (item && service) applyService(item, service);
      return;
    }
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
  itemContainer.addEventListener('input', event => {
    if (event.target.matches('.item-search')) {
      const item = event.target.closest('.line-item');
      const hadCatalogService = item.querySelector('.service-id').value !== '';
      item.querySelector('.service-id').value = '';
      item.querySelector('.item-name').value = event.target.value.trim();
      item.querySelector('.item-description').value = '';
      if (hadCatalogService) item.querySelector('.item-price').value = '';
      renderServiceSuggestions(event.target);
    }
    refresh();
  });
  itemContainer.addEventListener('focusin', event => {
    if (event.target.matches('.item-search') && event.target.value.trim()) renderServiceSuggestions(event.target);
  });
  itemContainer.addEventListener('keydown', event => {
    const item = event.target.closest('.line-item');
    if (!item) return;
    if (event.target.matches('.item-search') && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
      const options = [...item.querySelectorAll('.invoice-service-option')];
      if (!options.length) return;
      event.preventDefault();
      const current = options.findIndex(option => option.classList.contains('active'));
      const next = event.key === 'ArrowDown'
        ? (current + 1) % options.length
        : (current <= 0 ? options.length - 1 : current - 1);
      options.forEach((option, index) => option.classList.toggle('active', index === next));
      options[next].scrollIntoView({ block: 'nearest' });
      return;
    }
    if (event.key !== 'Enter') return;
    if (event.target.matches('.item-search')) {
      event.preventDefault();
      const list = item.querySelector('.invoice-service-suggestions');
      const selected = list?.querySelector('.invoice-service-option.active') || list?.querySelector('.invoice-service-option');
      if (selected) {
        const service = serviceCatalog.find(entry => String(entry.id) === selected.dataset.serviceId);
        if (service) applyService(item, service);
      } else if (event.target.value.trim()) {
        item.querySelector('.service-id').value = '';
        item.querySelector('.item-name').value = event.target.value.trim();
        item.querySelector('.item-description').value = '';
        closeServiceSuggestions();
        item.querySelector('.item-qty').focus();
        item.querySelector('.item-qty').select();
      }
      return;
    }
    if (event.target.matches('.item-qty')) {
      event.preventDefault();
      if (item.querySelector('.item-price').value !== '') focusNextInvoiceLine(item);
      else item.querySelector('.item-price').focus();
      return;
    }
    if (event.target.matches('.item-price')) {
      event.preventDefault();
      focusNextInvoiceLine(item);
    }
  });
  form.querySelectorAll('input[name="invoice_type"]').forEach(input => input.addEventListener('change', refresh));
  discountEnabled?.addEventListener('change', refresh);
  discountFields?.addEventListener('input', refresh);
  discountFields?.addEventListener('change', refresh);
  initialDiscountEnabled?.addEventListener('change', refresh);
  initialDiscountFields?.addEventListener('input', refresh);
  initialDiscountFields?.addEventListener('change', refresh);
  document.addEventListener('click', event => {
    if (!event.target.closest('.invoice-service-search')) closeServiceSuggestions();
  });
  form.addEventListener('submit', () => {
    itemContainer.querySelectorAll('.invoice-entry-row').forEach(item => {
      const search = item.querySelector('.item-search');
      if (search && !item.querySelector('.item-name').value.trim()) item.querySelector('.item-name').value = search.value.trim();
    });
  });
  refresh();
})();
