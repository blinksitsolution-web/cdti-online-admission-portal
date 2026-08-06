/**
 * Autosave + draft recovery for the registration form (Req. 2, 7).
 *
 * Loads after the page's main inline script, so `currentStep` / `updateStepUI`
 * (declared there with `let`/`function` at the top level of a classic script)
 * are already in the shared global scope and usable here directly.
 *
 * Entirely additive: if IndexedDB is unavailable (e.g. Safari private
 * browsing) every function here fails soft and the form works exactly as it
 * does today, online-submit only.
 */
// Service worker: scoped to /register only — never controls /admin or any
// other page. Registration failing (unsupported browser, blocked by policy)
// is non-fatal; the form still works fully online-submit only.
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/service-worker.js', { scope: '/register' })
    .catch(function (err) { console.warn('[register] service worker registration failed:', err); });
}

// Network + queue status indicator (Req. 6). Kept independent of the
// autosave IIFE below: connectivity display should still work even in a
// browser without IndexedDB, just without the queue-aware banner text.
(function () {
  'use strict';

  var pill = document.getElementById('networkStatus');
  var icon = document.getElementById('networkStatusIcon');
  var label = document.getElementById('networkStatusText');
  var banner = document.getElementById('queueBanner');
  var bannerText = document.getElementById('queueBannerText');
  var form = document.getElementById('regForm');
  var indexNumber = form ? (form.dataset.indexNumber || '') : '';
  if (!pill) return;

  function setPill(state, text, iconClass, spin) {
    pill.dataset.state = state;
    if (label) label.textContent = text;
    if (icon) icon.className = 'fa-solid ' + iconClass + (spin ? ' fa-spin-icon' : '');
  }

  function setBanner(text) {
    if (!banner || !bannerText) return;
    if (!text) {
      banner.classList.add('hidden');
      bannerText.textContent = '';
      return;
    }
    bannerText.textContent = text;
    banner.classList.remove('hidden');
  }

  function reflectConnectivity() {
    if (!navigator.onLine) {
      setPill('offline', 'Offline', 'fa-wifi', false);
      return;
    }
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn && (conn.saveData || conn.effectiveType === 'slow-2g' || conn.effectiveType === '2g')) {
      setPill('slow', 'Slow connection', 'fa-triangle-exclamation', false);
      return;
    }
    setPill('online', 'Online', 'fa-wifi', false);
  }

  function reflectQueueState() {
    if (!indexNumber || typeof OfflineDB === 'undefined') return;
    OfflineDB.getAllQueueItems().then(function (items) {
      var mine = items.filter(function (i) { return i.indexNumber === indexNumber; });
      var active = mine.find(function (i) { return i.state === 'pending' || i.state === 'uploading' || i.state === 'retry'; });
      var failed = mine.find(function (i) { return i.state === 'failed'; });

      if (active && active.state === 'uploading') {
        setPill('syncing', 'Syncing…', 'fa-arrows-rotate', true);
        setBanner('Submitting your registration…');
      } else if (active) {
        setBanner("Your registration is saved on this device and will be submitted automatically once you're online." +
          (active.lastError ? ' (' + active.lastError + ')' : ''));
      } else if (failed) {
        setBanner("We couldn't submit your registration: " + (failed.lastError || 'please try again') +
          ' — go to the Review step and submit again once this is resolved.');
      } else {
        setBanner(null);
      }
    }).catch(function () {});
  }

  window.addEventListener('online', function () {
    reflectConnectivity();
    if (window.CDTISyncEngine) window.CDTISyncEngine.drainQueue();
  });
  window.addEventListener('offline', reflectConnectivity);

  var networkInfo = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  if (networkInfo && networkInfo.addEventListener) {
    networkInfo.addEventListener('change', reflectConnectivity);
  }

  document.addEventListener('cdti:sync-status', function () {
    reflectConnectivity();
    reflectQueueState();
  });

  reflectConnectivity();
  reflectQueueState();
  setInterval(reflectQueueState, 15000);
})();

(function () {
  'use strict';

  var form = document.getElementById('regForm');
  if (!form || typeof OfflineDB === 'undefined') return;

  var indexNumber = form.dataset.indexNumber || '';
  var hasServerErrors = form.dataset.hasErrors === '1';
  var statusEl = document.getElementById('draftStatus');

  var AUTOSAVE_DEBOUNCE_MS = 1200;
  var AUTOSAVE_INTERVAL_MS = 20000;

  var clientUuid = null;
  var saveTimer = null;

  function setStatus(text) {
    if (statusEl) statusEl.textContent = text;
  }

  // Keeps the hidden client_uuid form field in sync with the in-memory
  // value, so a normal online Submit (not just the sync engine) also
  // carries it — register.php's idempotency check applies uniformly.
  function syncClientUuidField() {
    var field = document.getElementById('client_uuid');
    if (field) field.value = clientUuid || '';
  }

  // crypto.randomUUID() requires a secure context (HTTPS); crypto.getRandomValues
  // does not, so building the UUID from it keeps local http:// dev working too.
  function generateUuid() {
    if (window.crypto && typeof crypto.randomUUID === 'function') {
      try { return crypto.randomUUID(); } catch (e) { /* insecure context — fall through */ }
    }
    var bytes = new Uint8Array(16);
    if (window.crypto && typeof crypto.getRandomValues === 'function') {
      crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    var hex = Array.prototype.map.call(bytes, function (b) { return b.toString(16).padStart(2, '0'); });
    return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' + hex.slice(6, 8).join('') +
      '-' + hex.slice(8, 10).join('') + '-' + hex.slice(10, 16).join('');
  }

  function formToObject() {
    var data = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) return;
      if (el.name === 'csrf_token' || el.name === 'passport_photo' || el.name === 'client_uuid') return;
      if (el.type === 'checkbox') {
        data[el.name] = el.checked;
      } else if (el.type === 'radio') {
        if (el.checked) data[el.name] = el.value;
      } else {
        data[el.name] = el.value;
      }
    });
    return data;
  }

  function applyObjectToForm(data) {
    Object.keys(data || {}).forEach(function (name) {
      var el = form.elements[name];
      if (!el) return;
      if (el instanceof RadioNodeList) {
        Array.prototype.forEach.call(el, function (radio) {
          if (radio.type === 'radio') radio.checked = (radio.value === data[name]);
        });
        return;
      }
      if (el.type === 'checkbox') {
        el.checked = !!data[name];
      } else {
        el.value = data[name];
      }
    });
  }

  function currentPhotoSelection() {
    var fileInput = document.getElementById('passport_photo');
    if (fileInput && fileInput.files && fileInput.files[0]) {
      var file = fileInput.files[0];
      return { blob: file, meta: { name: file.name, type: file.type, size: file.size } };
    }
    return null;
  }

  function saveDraft() {
    if (!indexNumber) return Promise.resolve();
    return OfflineDB.getDraft(indexNumber).then(function (existing) {
      if (!clientUuid) clientUuid = (existing && existing.clientUuid) || generateUuid();
      syncClientUuidField();
      var photo = currentPhotoSelection();
      var record = {
        clientUuid: clientUuid,
        formData: formToObject(),
        currentStep: currentStep,
        photoBlob: photo ? photo.blob : (existing ? existing.photoBlob : null),
        photoMeta: photo ? photo.meta : (existing ? existing.photoMeta : null),
      };
      return OfflineDB.saveDraft(indexNumber, record);
    }).then(function () {
      setStatus('Draft saved ' + new Date().toLocaleTimeString());
    }).catch(function () {
      // Autosave is a convenience, not a requirement — stay silent and let
      // the student keep filling the form normally.
      setStatus('');
    });
  }

  function scheduleSave() {
    setStatus('Saving…');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDraft, AUTOSAVE_DEBOUNCE_MS);
  }

  form.addEventListener('input', scheduleSave);
  form.addEventListener('change', scheduleSave);
  setInterval(saveDraft, AUTOSAVE_INTERVAL_MS);
  window.addEventListener('beforeunload', function () { saveDraft(); });

  // Called by previewPhoto() in the inline script once its async compression
  // finishes — saves immediately instead of waiting on the debounce timer,
  // so the draft never captures a stale (pre-compression) photo Blob.
  window.CDTI_onPhotoReady = function () {
    clearTimeout(saveTimer);
    saveDraft();
  };

  // Submit handling: every submit goes through fetch(), never a raw native
  // form POST. This is deliberate — navigator.onLine only reports whether a
  // network interface exists, not whether it actually reaches the internet
  // (e.g. Wi-Fi with no working uplink still reads `true`). Trusting it to
  // decide whether to *risk* a real page navigation meant a validation error
  // on a "looks online but isn't" connection could still blow away the form.
  // Now: try a live submit; only a real failed attempt falls back to the
  // offline queue, and every outcome (success, validation error, conflict)
  // is handled without ever navigating the page away.
  var baseUrl = form.dataset.baseUrl || '';
  var submitBtn = document.getElementById('submitBtn');
  var FETCH_TIMEOUT_MS = 15000;

  function fetchWithTimeout(url, options) {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, FETCH_TIMEOUT_MS);
    return fetch(url, Object.assign({}, options, { signal: controller.signal }))
      .finally(function () { clearTimeout(timer); });
  }

  function setSubmitting(isSubmitting) {
    if (submitBtn) submitBtn.disabled = isSubmitting;
  }

  function buildSyncFormData(snapshot, token) {
    var fd = new FormData();
    fd.append('csrf_token', token);
    fd.append('sync', '1');
    fd.append('client_uuid', snapshot.clientUuid);
    var data = snapshot.formData || {};
    Object.keys(data).forEach(function (key) {
      var val = data[key];
      if (val === false) return; // unchecked checkbox — omit, matches native form encoding
      if (val === true) val = '1';
      fd.append(key, val == null ? '' : val);
    });
    if (snapshot.photoBlob) {
      fd.append('passport_photo', snapshot.photoBlob, (snapshot.photoMeta && snapshot.photoMeta.name) || 'passport_photo.jpg');
    }
    return fd;
  }

  function renderErrors(errors) {
    var banner = document.getElementById('formErrors');
    if (banner) {
      banner.innerHTML = '';
      (errors || []).forEach(function (err) {
        var div = document.createElement('div');
        var icon = document.createElement('i');
        icon.className = 'fa-solid fa-triangle-exclamation';
        icon.setAttribute('aria-hidden', 'true');
        div.appendChild(icon);
        div.appendChild(document.createTextNode(' ' + err.msg));
        banner.appendChild(div);
      });
      banner.classList.remove('hidden');
    }
    var first = (errors && errors[0]) || {};
    if (window.CDTI_focusFirstError) window.CDTI_focusFirstError(first.step || null, first.field || null);
  }

  function queueOffline(snapshot) {
    return OfflineDB.enqueueSubmission(snapshot).then(function () {
      return Swal.fire({
        icon: 'info',
        title: "You're offline",
        text: "No problem — we've saved your registration on this device. It will be submitted automatically as soon as you're back online.",
        confirmButtonText: 'Got it',
        background: '#0f1e2d',
        color: '#fff',
      });
    }).catch(function () {
      return Swal.fire({
        icon: 'error',
        title: 'Could not save',
        text: "We couldn't save your registration on this device. Please try submitting again once you have a connection.",
        background: '#0f1e2d',
        color: '#fff',
      });
    });
  }

  function handleSubmitResult(data) {
    if (data.status === 'success') {
      return OfflineDB.deleteDraft(indexNumber).then(function () {
        window.location.href = baseUrl + '/dashboard';
      });
    }
    if (data.status === 'conflict' && data.reason === 'already_registered') {
      return OfflineDB.deleteDraft(indexNumber).then(function () {
        return Swal.fire({
          icon: 'info',
          title: 'Already completed',
          text: data.message || 'Your registration was already completed — no action needed.',
          confirmButtonText: 'Go to dashboard',
          background: '#0f1e2d',
          color: '#fff',
        });
      }).then(function () { window.location.href = baseUrl + '/dashboard'; });
    }
    if (data.status === 'conflict' && data.reason === 'payment_required') {
      return Swal.fire({
        icon: 'warning',
        title: 'Payment required',
        text: data.message || 'Your payment needs to be completed before this can be submitted.',
        confirmButtonText: 'Go to payment',
        background: '#0f1e2d',
        color: '#fff',
      }).then(function () { window.location.href = baseUrl + '/payment'; });
    }
    if (data.status === 'error' && data.reason === 'validation') {
      renderErrors(data.errors);
      return;
    }
    if (data.status === 'error' && data.reason === 'rate_limited') {
      return Swal.fire({
        icon: 'warning',
        title: 'Too many attempts',
        text: data.message || 'Please wait before trying again.',
        background: '#0f1e2d',
        color: '#fff',
      });
    }
    // Unexpected response shape — surface it, but nothing is lost: the
    // draft in IndexedDB and the on-screen form are both still intact.
    return Swal.fire({
      icon: 'error',
      title: 'Something went wrong',
      text: 'Please try again in a moment.',
      background: '#0f1e2d',
      color: '#fff',
    });
  }

  function submitLive(snapshot) {
    return fetchWithTimeout('/csrf_token', { credentials: 'same-origin' }).then(function (res) {
      if (res.status === 401) {
        var err = new Error('session_expired');
        err.code = 'session_expired';
        throw err;
      }
      if (!res.ok) throw new Error('token_fetch_failed');
      return res.json();
    }).then(function (tokenData) {
      return fetchWithTimeout('/register.php', {
        method: 'POST',
        body: buildSyncFormData(snapshot, tokenData.token),
        credentials: 'same-origin',
      });
    }).then(function (response) {
      return response.json(); // every sync-mode response (200/409/422/429) is JSON
    }).then(function (data) {
      return handleSubmitResult(data);
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    setSubmitting(true);

    OfflineDB.getDraft(indexNumber).then(function (existing) {
      if (!clientUuid) clientUuid = (existing && existing.clientUuid) || generateUuid();
      syncClientUuidField();
      var photo = currentPhotoSelection();
      var snapshot = {
        clientUuid: clientUuid,
        indexNumber: indexNumber,
        formData: formToObject(),
        currentStep: currentStep,
        photoBlob: photo ? photo.blob : (existing ? existing.photoBlob : null),
        photoMeta: photo ? photo.meta : (existing ? existing.photoMeta : null),
      };
      return OfflineDB.saveDraft(indexNumber, snapshot).then(function () { return snapshot; });
    }).then(function (snapshot) {
      // Always attempt a real submission first, even if navigator.onLine
      // claims offline — that flag can get stuck reporting the wrong value
      // after a real connectivity change, with no reload to reset it. Only
      // an actual failed attempt falls back to the queue.
      return submitLive(snapshot).catch(function (err) {
        if (err && err.code === 'session_expired') {
          return Swal.fire({
            icon: 'warning',
            title: 'Session expired',
            text: 'Please sign in again — your information is saved on this device and nothing was lost.',
            confirmButtonText: 'Sign in',
            background: '#0f1e2d',
            color: '#fff',
          }).then(function () { window.location.href = baseUrl + '/admissions'; });
        }
        // Any other failure (genuinely offline, timeout, server unreachable)
        // regardless of what navigator.onLine claimed — fall back to the
        // offline queue rather than losing the submission.
        return queueOffline(snapshot);
      });
    }).catch(function () {
      Swal.fire({
        icon: 'error',
        title: 'Could not save',
        text: "We couldn't save your registration on this device. Please try again.",
        background: '#0f1e2d',
        color: '#fff',
      });
    }).then(function () {
      setSubmitting(false);
    });
  });

  function restorePhotoIntoInput(photoBlob, photoMeta) {
    if (!photoBlob) return;
    var fileInput = document.getElementById('passport_photo');
    if (!fileInput) return;
    try {
      var file = new File([photoBlob], (photoMeta && photoMeta.name) || 'passport_photo.jpg', {
        type: (photoMeta && photoMeta.type) || photoBlob.type,
      });
      var dt = new DataTransfer();
      dt.items.add(file);
      fileInput.files = dt.files;
      var imgPreview = document.getElementById('photoPreview');
      var photoInfo = document.getElementById('photoInfo');
      if (imgPreview) {
        imgPreview.src = URL.createObjectURL(file);
        imgPreview.classList.remove('hidden');
      }
      if (photoInfo) photoInfo.textContent = file.name + ' (restored from your saved draft)';
    } catch (e) { /* best effort — the rest of the draft still restores */ }
  }

  function hasAnyContent(formData) {
    return Object.keys(formData || {}).some(function (k) {
      var v = formData[k];
      return v !== '' && v !== false && v !== null && v !== undefined;
    });
  }

  function offerDraftRecovery() {
    // A server-rendered validation error means the DOM already holds the
    // freshest data (what the student just submitted) — an older draft
    // must not clobber it.
    if (!indexNumber || hasServerErrors) return;

    OfflineDB.purgeStaleDrafts().catch(function () {});

    OfflineDB.getDraft(indexNumber).then(function (draft) {
      if (!draft || !hasAnyContent(draft.formData)) return;

      var savedAt = draft.updatedAt ? new Date(draft.updatedAt).toLocaleString() : 'earlier';
      return Swal.fire({
        icon: 'question',
        title: 'Resume saved draft?',
        text: 'We found information you started entering on ' + savedAt + '. Continue where you left off?',
        showCancelButton: true,
        confirmButtonText: 'Resume draft',
        cancelButtonText: 'Start fresh',
        background: '#0f1e2d',
        color: '#fff',
      }).then(function (result) {
        if (result.isConfirmed) {
          clientUuid = draft.clientUuid || null;
          syncClientUuidField();
          applyObjectToForm(draft.formData);
          restorePhotoIntoInput(draft.photoBlob, draft.photoMeta);
          if (draft.currentStep) {
            currentStep = draft.currentStep;
            updateStepUI(currentStep);
          }
          setStatus('Draft restored.');
          // Move focus (and the viewport) to the restored step's heading —
          // otherwise a keyboard/screen-reader user's focus is left on the
          // now-dismissed dialog's trigger with no indication where they landed.
          window.scrollTo(0, 0);
          var heading = document.querySelector('#step' + currentStep + ' h5');
          if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
          }
        } else {
          clientUuid = null;
          syncClientUuidField();
          return OfflineDB.deleteDraft(indexNumber);
        }
      });
    }).catch(function () {
      // No usable draft / IndexedDB error — proceed with the normal, blank form.
    });
  }

  offerDraftRecovery();
})();
