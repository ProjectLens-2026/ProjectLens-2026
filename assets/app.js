(() => {
  const navToggle = document.querySelector('.mobile-toggle');
  const nav = document.querySelector('.nav-links');
  navToggle?.addEventListener('click', () => {
    const expanded = nav.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', String(expanded));
    navToggle.textContent = expanded ? '✕' : '☰';
  });
  document.querySelectorAll('form[data-validate-password]').forEach(form => {
    form.addEventListener('submit', event => {
      const pass = form.querySelector('[name="password"], [name="new_password"]');
      const repeat = form.querySelector('[name="confirm_password"]');
      if (pass && repeat && pass.value !== repeat.value) {
        repeat.setCustomValidity('Passwords must match.');
        repeat.reportValidity();
        event.preventDefault();
      }
    });
    form.querySelector('[name="confirm_password"]')?.addEventListener('input', event => event.target.setCustomValidity(''));
  });
  const dialog = document.querySelector('#reportDialog');
  document.querySelectorAll('.report-open').forEach(button => button.addEventListener('click', () => {
    if (!dialog) return;
    dialog.querySelector('#reportProjectId').value = button.dataset.project;
    dialog.showModal();
  }));
  document.querySelectorAll('.modal-close').forEach(button => button.addEventListener('click', () => dialog?.close()));
  dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  const projectSelect = document.querySelector('#projectSelect');
  const activitySelect = document.querySelector('#activitySelect');
  function filterActivities() {
    if (!projectSelect || !activitySelect) return;
    const selected = projectSelect.value;
    for (const option of activitySelect.querySelectorAll('option[data-project]')) {
      const match = !!selected && option.dataset.project === selected;
      option.hidden = !match;
      option.disabled = !match;
    }
    activitySelect.value = '';
  }
  projectSelect?.addEventListener('change', filterActivities);
  if (projectSelect) filterActivities();
  const photo = document.querySelector('#photoInput');
  photo?.addEventListener('change', () => {
    const file = photo.files?.[0];
    const preview = document.querySelector('#uploadPreview');
    if (!preview) return;
    preview.replaceChildren();
    if (!file) return;
    if (!file.type.startsWith('image/')) { preview.textContent = 'Select an image file'; return; }
    const image = document.createElement('img');
    const uri = URL.createObjectURL(file);
    image.src = uri; image.alt = `Preview of ${file.name}`;
    image.onload = () => URL.revokeObjectURL(uri);
    preview.appendChild(image);
  });
  document.querySelectorAll('.copy-code').forEach(button => button.addEventListener('click', async () => {
    const code = button.dataset.copy;
    if (!code) return;
    try {
      await navigator.clipboard.writeText(code);
      const previous = button.textContent;
      button.textContent = 'Copied!';
      setTimeout(() => { button.textContent = previous; }, 1500);
    } catch (error) {
      window.prompt('Copy your project code:', code);
    }
  }));
  const evidenceTrack = document.querySelector('#projectEvidenceCarousel');
  evidenceTrack?.closest('.content-card')?.querySelector('.gallery-scroll-back')?.addEventListener('click', () => {
    evidenceTrack.scrollBy({left: -Math.max(240, evidenceTrack.clientWidth * 0.8), behavior: 'smooth'});
  });
  evidenceTrack?.closest('.content-card')?.querySelector('.gallery-scroll-next')?.addEventListener('click', () => {
    evidenceTrack.scrollBy({left: Math.max(240, evidenceTrack.clientWidth * 0.8), behavior: 'smooth'});
  });
  const photoButtons = Array.from(document.querySelectorAll('[data-gallery-open]'));
  const viewer = document.querySelector('#evidenceViewer');
  const fullImage = document.querySelector('#evidenceViewerImage');
  const imageArea = document.querySelector('#evidenceImageArea');
  if (viewer && fullImage && imageArea && photoButtons.length) {
    let currentImage = 0;
    let zoom = 1;
    let panX = 0;
    let panY = 0;
    let drag = null;
    function displayTransform() {
      fullImage.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
      imageArea.classList.toggle('can-drag', zoom > 1);
      viewer.querySelector('[data-zoom-reset]').textContent = `Reset (${Math.round(zoom * 100)}%)`;
    }
    function resetZoom() {
      zoom = 1; panX = 0; panY = 0; drag = null;
      displayTransform();
    }
    function changeZoom(delta) {
      zoom = Math.max(1, Math.min(5, Math.round((zoom + delta) * 100) / 100));
      if (zoom === 1) { panX = 0; panY = 0; }
      displayTransform();
    }
    function displayPhoto(index) {
      currentImage = (index + photoButtons.length) % photoButtons.length;
      const button = photoButtons[currentImage];
      const smallImage = button.querySelector('img');
      if (!smallImage) return;
      fullImage.src = smallImage.currentSrc || smallImage.src;
      fullImage.alt = smallImage.alt;
      viewer.querySelector('#evidenceViewerTitle').textContent = button.dataset.title || 'Site photograph';
      viewer.querySelector('#evidenceViewerCaption').textContent = `Photo date: ${button.dataset.date || 'Not recorded'}`;
      viewer.querySelector('#evidenceViewerCount').textContent = `${currentImage + 1} of ${photoButtons.length}`;
      resetZoom();
    }
    photoButtons.forEach((button, index) => button.addEventListener('click', () => {
      displayPhoto(index);
      viewer.showModal();
    }));
    viewer.querySelector('[data-gallery-close]')?.addEventListener('click', () => viewer.close());
    viewer.querySelector('[data-gallery-prev]')?.addEventListener('click', () => displayPhoto(currentImage - 1));
    viewer.querySelector('[data-gallery-next]')?.addEventListener('click', () => displayPhoto(currentImage + 1));
    viewer.querySelector('[data-zoom-in]')?.addEventListener('click', () => changeZoom(0.25));
    viewer.querySelector('[data-zoom-out]')?.addEventListener('click', () => changeZoom(-0.25));
    viewer.querySelector('[data-zoom-reset]')?.addEventListener('click', resetZoom);
    imageArea.addEventListener('wheel', event => {
      event.preventDefault();
      changeZoom(event.deltaY < 0 ? 0.25 : -0.25);
    }, {passive: false});
    imageArea.addEventListener('pointerdown', event => {
      if (zoom <= 1 || event.button !== 0) return;
      drag = {x: event.clientX, y: event.clientY, panX, panY};
      imageArea.setPointerCapture(event.pointerId);
      imageArea.classList.add('dragging');
    });
    imageArea.addEventListener('pointermove', event => {
      if (!drag) return;
      panX = drag.panX + event.clientX - drag.x;
      panY = drag.panY + event.clientY - drag.y;
      displayTransform();
    });
    const endDrag = () => {drag = null; imageArea.classList.remove('dragging');};
    imageArea.addEventListener('pointerup', endDrag);
    imageArea.addEventListener('pointercancel', endDrag);
    viewer.addEventListener('keydown', event => {
      if (event.key === 'ArrowRight') {event.preventDefault(); displayPhoto(currentImage + 1);}
      else if (event.key === 'ArrowLeft') {event.preventDefault(); displayPhoto(currentImage - 1);}
      else if (event.key === '+' || event.key === '=') {event.preventDefault(); changeZoom(0.25);}
      else if (event.key === '-') {event.preventDefault(); changeZoom(-0.25);}
      else if (event.key === '0') {event.preventDefault(); resetZoom();}
    });
    viewer.addEventListener('click', event => {if (event.target === viewer) viewer.close();});
    viewer.addEventListener('close', () => {fullImage.removeAttribute('src'); resetZoom();});
  }
  const ownerActivity = document.getElementById('ownerActivity');
  const ownerActivityProject = document.getElementById('ownerActivityProject');
  if (ownerActivity && ownerActivityProject) {
    const updateProject = () => {
      const option = ownerActivity.selectedOptions[0];
      ownerActivityProject.value = option?.dataset.project || '';
    };
    ownerActivity.addEventListener('change', updateProject);
    updateProject();
  }
  const focus = new URLSearchParams(location.search).get('focus');
  if (focus && /^\d+$/.test(focus)) document.getElementById(`evidence-${focus}`)?.scrollIntoView({block:'center'});
  if (document.body.dataset.role === 'client' && document.querySelector('[data-live-section]')) {
    const sections = Array.from(document.querySelectorAll('[data-live-section]'));
    let busy = false;
    setInterval(async () => {
      if (busy || document.hidden || document.querySelector('dialog[open]') || document.activeElement?.matches('input, textarea, select')) return;
      busy = true;
      try {
        const response = await fetch(window.location.href, {cache:'no-store',credentials:'same-origin',headers:{'X-Requested-With':'ProjectLens-Refresh'}});
        if (!response.ok) return;
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        for (const section of sections) {
          if (!section.isConnected) continue;
          const next = page.querySelector('[data-live-section="'+section.dataset.liveSection+'"]');
          if (next && section.innerHTML !== next.innerHTML) section.innerHTML = next.innerHTML;
        }
      } catch (error) { }
      finally {busy = false;}
    }, 30000);
  }
})();
