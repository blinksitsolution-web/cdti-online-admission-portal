/**
 * kiosk-app.js — Offline Kiosk Registration Application
 *
 * Drives offline-kiosk.html. Handles:
 *  - Screen navigation (welcome → verify → form steps → success)
 *  - Student lookup from KioskDB
 *  - Form auto-save to KioskDB (debounced + interval)
 *  - Photo capture / preview
 *  - Live submit to sync-kiosk.php with offline queue fallback
 *  - Background sync drain on reconnect
 *
 * Requires kiosk-db.js to be loaded first.
 */
(function () {
  'use strict';

  if (typeof KioskDB === 'undefined') {
    console.error('[kiosk] KioskDB not loaded.');
    return;
  }

  // ── State ────────────────────────────────────────────────────────────────
  var currentScreen = 'welcome';
  var currentStep   = 1;
  var totalSteps    = 5;
  var student       = null;   // { idx, name, prog, gen, res, agg, paid, code }
  var photoBlob     = null;
  var photoMeta     = null;
  var clientUuid    = null;
  var saveTimer     = null;
  var SAVE_DEBOUNCE = 1200;
  var SAVE_INTERVAL = 20000;

  // ── UUID generator (crypto-safe, works on http://) ───────────────────────
  function generateUuid() {
    if (window.crypto && typeof crypto.randomUUID === 'function') {
      try { return crypto.randomUUID(); } catch (e) { /* fall through */ }
    }
    var b = new Uint8Array(16);
    if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(b);
    else for (var i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return x.toString(16).padStart(2, '0'); });
    return h.slice(0,4).join('')+'-'+h.slice(4,6).join('')+'-'+h.slice(6,8).join('')+'-'+h.slice(8,10).join('')+'-'+h.slice(10).join('');
  }

  // ── Screen management ────────────────────────────────────────────────────
  function showScreen(name) {
    document.querySelectorAll('.k-screen').forEach(function (el) {
      el.classList.toggle('active', el.dataset.screen === name);
    });
    currentScreen = name;
    window.scrollTo(0, 0);
  }

  function showStep(n) {
    document.querySelectorAll('.k-step').forEach(function (el) {
      el.classList.toggle('active', parseInt(el.dataset.step, 10) === n);
    });
    document.querySelectorAll('.k-step-dot').forEach(function (el, i) {
      el.classList.remove('active', 'done');
      if (i + 1 === n) el.classList.add('active');
      else if (i + 1 < n) el.classList.add('done');
    });
    currentStep = n;
    window.scrollTo(0, 0);
    var heading = document.querySelector('[data-step="' + n + '"] .k-step-title');
    if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); }
  }

  // ── Network pill ─────────────────────────────────────────────────────────
  function updateNetworkPill() {
    var pill = document.getElementById('kNetworkPill');
    if (!pill) return;
    if (!navigator.onLine) {
      pill.textContent = '📴 Offline';
      pill.style.background = 'rgba(220,60,60,0.25)';
      pill.style.borderColor = 'rgba(220,60,60,0.5)';
    } else {
      pill.textContent = '📶 Online';
      pill.style.background = 'rgba(45,198,83,0.2)';
      pill.style.borderColor = 'rgba(45,198,83,0.4)';
    }
  }
  window.addEventListener('online',  function () { updateNetworkPill(); drainQueue(); });
  window.addEventListener('offline', updateNetworkPill);
  updateNetworkPill();

  // ── Load school settings for offline display ─────────────────────────────
  function applySchoolMeta() {
    KioskDB.getSchoolMeta().then(function (meta) {
      if (!meta) return;
      var nameEl = document.getElementById('kSchoolName');
      var logoEl = document.getElementById('kSchoolLogo');
      var yearEl = document.getElementById('kAcademicYear');
      if (nameEl) nameEl.textContent = meta.name || 'CDTI';
      if (yearEl) yearEl.textContent = meta.year || new Date().getFullYear();
      if (logoEl && meta.logoUrl) {
        logoEl.src = meta.logoUrl;
        logoEl.style.display = 'block';
      }
    }).catch(function () {});
  }

  // ── Mask name for privacy (show first 2 chars + *** per word) ────────────
  function maskName(fullName) {
    return (fullName || '').split(' ').map(function (word) {
      if (word.length <= 2) return word;
      return word.slice(0, 2) + '*'.repeat(word.length - 2);
    }).join(' ');
  }

  // ── Welcome screen: index number lookup ──────────────────────────────────
  function initWelcomeScreen() {
    var form   = document.getElementById('kWelcomeForm');
    var input  = document.getElementById('kIndexInput');
    var errEl  = document.getElementById('kWelcomeError');
    var btn    = document.getElementById('kWelcomeBtn');

    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var idx = (input.value || '').trim().replace(/\s+/g, '');
      if (!idx) { errEl.textContent = 'Please enter your index number.'; errEl.style.display = 'block'; return; }
      errEl.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Searching…';

      KioskDB.lookupStudent(idx).then(function (found) {
        btn.disabled = false;
        btn.textContent = 'Find My Record →';
        if (!found) {
          // Try case-insensitive search (idx may be stored differently)
          return KioskDB.getStudentCount().then(function (count) {
            if (count === 0) {
              errEl.textContent = 'No student data has been downloaded yet. Please ask the admin to sync the kiosk data first.';
            } else {
              errEl.textContent = 'Index number not found in the kiosk database (' + count + ' students loaded). Check your number and try again.';
            }
            errEl.style.display = 'block';
          });
        }
        student = found;
        // Show verify screen
        var maskedEl = document.getElementById('kMaskedName');
        var progEl   = document.getElementById('kStudentProg');
        var resEl    = document.getElementById('kStudentRes');
        if (maskedEl) maskedEl.textContent = maskName(student.name);
        if (progEl)   progEl.textContent   = student.prog || '—';
        if (resEl)    resEl.textContent    = student.res  || '—';
        showScreen('verify');
      }).catch(function (err) {
        btn.disabled = false;
        btn.textContent = 'Find My Record →';
        errEl.textContent = 'Could not search: ' + (err.message || 'unknown error');
        errEl.style.display = 'block';
      });
    });
  }

  // ── Verify screen ─────────────────────────────────────────────────────────
  function initVerifyScreen() {
    var yesBtn = document.getElementById('kVerifyYes');
    var noBtn  = document.getElementById('kVerifyNo');
    if (yesBtn) yesBtn.addEventListener('click', function () {
      // Check for existing draft
      KioskDB.getDraft(student.idx).then(function (draft) {
        if (draft && draft.formData && Object.keys(draft.formData).length > 0) {
          if (confirm('We found a saved draft from ' + (draft.updatedAt ? new Date(draft.updatedAt).toLocaleString() : 'earlier') + '. Continue where you left off?')) {
            restoreDraft(draft);
          }
        }
        showScreen('form');
        showStep(1);
        prefillFromStudent();
      }).catch(function () {
        showScreen('form');
        showStep(1);
        prefillFromStudent();
      });
    });
    if (noBtn) noBtn.addEventListener('click', function () {
      student = null;
      var input = document.getElementById('kIndexInput');
      if (input) { input.value = ''; input.focus(); }
      showScreen('welcome');
    });
  }

  // ── Pre-fill form with student's known data ───────────────────────────────
  function prefillFromStudent() {
    if (!student) return;
    var nameEl = document.getElementById('kFormStudentName');
    var idxEl  = document.getElementById('kFormStudentIdx');
    var progEl = document.getElementById('kFormStudentProg');
    var resEl  = document.getElementById('kFormStudentRes');
    if (nameEl) nameEl.textContent = student.name || '—';
    if (idxEl)  idxEl.textContent  = student.idx  || '—';
    if (progEl) progEl.textContent = student.prog  || '—';
    if (resEl)  resEl.textContent  = student.res   || '—';

    var aggEl = document.getElementById('aggregate');
    if (aggEl && student.agg) aggEl.value = student.agg;

    // Show/hide boarder info
    var boarderSection = document.getElementById('kBoarderSection');
    var isBoarder = /boarder|boarding/i.test(student.res || '');
    if (boarderSection) boarderSection.style.display = isBoarder ? 'block' : 'none';
  }

  // ── Form auto-save ────────────────────────────────────────────────────────
  function formToObject() {
    var form = document.getElementById('kRegForm');
    if (!form) return {};
    var data = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) return;
      if (el.name === 'passport_photo') return;
      if (el.type === 'checkbox') data[el.name] = el.checked;
      else if (el.type === 'radio') { if (el.checked) data[el.name] = el.value; }
      else data[el.name] = el.value;
    });
    return data;
  }

  function applyObjectToForm(data) {
    var form = document.getElementById('kRegForm');
    if (!form || !data) return;
    Object.keys(data).forEach(function (name) {
      var el = form.elements[name];
      if (!el) return;
      if (el instanceof RadioNodeList) {
        Array.prototype.forEach.call(el, function (r) { if (r.type === 'radio') r.checked = r.value === data[name]; });
        return;
      }
      if (el.type === 'checkbox') el.checked = !!data[name];
      else el.value = data[name];
    });
  }

  function saveDraft() {
    if (!student) return;
    KioskDB.saveDraft(student.idx, {
      clientUuid: clientUuid,
      formData: formToObject(),
      currentStep: currentStep,
      photoMeta: photoMeta,
    }).catch(function () {});
  }

  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDraft, SAVE_DEBOUNCE);
  }

  function restoreDraft(draft) {
    if (draft.clientUuid) clientUuid = draft.clientUuid;
    applyObjectToForm(draft.formData);
    if (draft.currentStep) { currentStep = draft.currentStep; }
    if (draft.photoMeta) photoMeta = draft.photoMeta;
  }

  function initFormAutoSave() {
    var form = document.getElementById('kRegForm');
    if (!form) return;
    form.addEventListener('input',  scheduleSave);
    form.addEventListener('change', scheduleSave);
    setInterval(saveDraft, SAVE_INTERVAL);
    window.addEventListener('beforeunload', saveDraft);
  }

  // ── Step navigation ───────────────────────────────────────────────────────
  function validateCurrentStep() {
    var errors = [];
    if (currentStep === 1) {
      if (!document.getElementById('dob').value)       errors.push('Date of Birth is required.');
      if (!document.getElementById('religion').value)  errors.push('Religion is required.');
      if (!document.getElementById('hometown').value)  errors.push('Hometown is required.');
      if (!document.getElementById('region').value)    errors.push('Region is required.');
      if (!document.getElementById('prev_jhs').value)  errors.push('Previous JHS name is required.');
    }
    if (currentStep === 2) {
      var agg = document.getElementById('aggregate');
      if (agg && !agg.value) errors.push('BECE aggregate is required.');
      var ec  = document.getElementById('enrolment_code');
      if (ec && !ec.value)   errors.push('Enrolment code is required.');
    }
    if (errors.length) {
      showKioskAlert(errors.join('\n'));
      return false;
    }
    return true;
  }

  function showKioskAlert(msg) {
    var el = document.getElementById('kStepError');
    if (el) { el.textContent = msg; el.style.display = 'block'; setTimeout(function() { el.style.display='none'; }, 5000); }
    else alert(msg);
  }

  function initStepNavigation() {
    document.addEventListener('click', function (e) {
      if (e.target.matches('[data-next-step]')) {
        if (!validateCurrentStep()) return;
        var next = parseInt(e.target.dataset.nextStep, 10);
        saveDraft();
        if (next > totalSteps) {
          populateReview();
          showStep(5); // review
        } else {
          showStep(next);
        }
      }
      if (e.target.matches('[data-prev-step]')) {
        showStep(parseInt(e.target.dataset.prevStep, 10));
      }
    });
  }

  // ── Review panel population ───────────────────────────────────────────────
  function populateReview() {
    var fields = {
      rv_dob:      document.getElementById('dob'),
      rv_religion: document.getElementById('religion'),
      rv_hometown: document.getElementById('hometown'),
      rv_region:   document.getElementById('region'),
      rv_jhs:      document.getElementById('prev_jhs'),
      rv_agg:      document.getElementById('aggregate'),
      rv_code:     document.getElementById('enrolment_code'),
    };
    Object.keys(fields).forEach(function (id) {
      var el = document.getElementById(id);
      if (el && fields[id]) el.textContent = fields[id].value || '—';
    });
    var rvPhoto = document.getElementById('rv_photo');
    var preview = document.getElementById('kPhotoPreview');
    if (rvPhoto && preview && preview.src) {
      rvPhoto.src = preview.src;
      rvPhoto.style.display = 'block';
    }
  }

  // ── Photo handling ────────────────────────────────────────────────────────
  function initPhotoUpload() {
    var input   = document.getElementById('passport_photo');
    var preview = document.getElementById('kPhotoPreview');
    var info    = document.getElementById('kPhotoInfo');
    if (!input) return;

    input.addEventListener('change', function (e) {
      var file = e.target.files[0];
      if (!file) return;
      if (!['image/jpeg','image/jpg','image/png'].includes(file.type)) {
        showKioskAlert('Please select a JPG or PNG image.');
        input.value = '';
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        showKioskAlert('Photo must be smaller than 5MB.');
        input.value = '';
        return;
      }
      photoBlob = file;
      photoMeta = { name: file.name, type: file.type, size: file.size };
      var reader = new FileReader();
      reader.onload = function (ev) {
        if (preview) { preview.src = ev.target.result; preview.style.display = 'block'; }
        if (info) info.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
        saveDraft();
      };
      reader.readAsDataURL(file);
    });
  }

  // ── Submit ────────────────────────────────────────────────────────────────
  function buildFormData() {
    var fd = new FormData();
    if (!clientUuid) clientUuid = generateUuid();
    fd.append('client_uuid', clientUuid);
    fd.append('index_number', student.idx);

    var data = formToObject();
    Object.keys(data).forEach(function (k) {
      var v = data[k];
      if (v === false) return;
      if (v === true) v = '1';
      fd.append(k, v == null ? '' : v);
    });

    if (photoBlob) {
      fd.append('passport_photo', photoBlob, (photoMeta && photoMeta.name) || 'photo.jpg');
    }
    return fd;
  }

  function showSubmitStatus(msg, isError) {
    var el = document.getElementById('kSubmitStatus');
    if (!el) return;
    el.textContent = msg;
    el.style.color = isError ? '#ff6b6b' : 'rgba(255,255,255,0.7)';
    el.style.display = 'block';
  }

  function showSuccess(admissionNumber) {
    var numEl = document.getElementById('kAdmissionNumber');
    var houseEl = document.getElementById('kHouseMsg');
    if (numEl) {
      if (admissionNumber) {
        numEl.textContent = admissionNumber;
        numEl.style.display = 'block';
        document.getElementById('kAdmissionLabel').style.display = 'block';
      }
    }
    var isBoarder = /boarder|boarding/i.test((student && student.res) || '');
    if (houseEl) houseEl.style.display = isBoarder ? 'block' : 'none';

    if (student) {
      KioskDB.deleteDraft(student.idx).catch(function () {});
      var nameEl = document.getElementById('kSuccessName');
      if (nameEl) nameEl.textContent = student.name || '';
    }
    showScreen('success');
  }

  function queueOffline() {
    if (!student || !clientUuid) return Promise.resolve();
    var entry = {
      clientUuid:  clientUuid,
      indexNumber: student.idx,
      formData:    formToObject(),
      photoBlob:   photoBlob,
      photoMeta:   photoMeta,
      state:       'pending',
      attempts:    0,
    };
    return KioskDB.enqueue(entry).then(function () {
      var el = document.getElementById('kQueuedBanner');
      if (el) el.style.display = 'flex';
    });
  }

  function submitLive() {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 20000);
    return fetch('/sync-kiosk.php', {
      method: 'POST',
      body: buildFormData(),
      signal: controller.signal,
    }).finally(function () { clearTimeout(timer); })
      .then(function (res) { return res.json(); });
  }

  function handleSubmitResult(data) {
    if (data.status === 'success') {
      showSuccess(data.admission_number || null);
      return;
    }
    if (data.status === 'conflict' && data.reason === 'already_registered') {
      showSuccess(null);
      return;
    }
    if (data.status === 'error' && data.reason === 'validation') {
      var msg = (data.errors || []).map(function(e) { return e.msg; }).join('\n');
      showSubmitStatus('Please fix: ' + msg, true);
      return;
    }
    showSubmitStatus((data.message || 'Submission failed. Will retry when online.'), true);
    queueOffline();
  }

  function initSubmit() {
    var btn = document.getElementById('kSubmitBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var decl = document.getElementById('kDeclaration');
      if (decl && !decl.checked) {
        showKioskAlert('You must accept the declaration before submitting.');
        return;
      }
      if (!clientUuid) clientUuid = generateUuid();
      btn.disabled = true;
      showSubmitStatus('Submitting…', false);

      saveDraft();
      submitLive().then(handleSubmitResult).catch(function () {
        showSubmitStatus('No connection — your registration is saved on this device and will sync automatically when online.', false);
        queueOffline();
      }).finally(function () { btn.disabled = false; });
    });
  }

  // ── Background sync drain ─────────────────────────────────────────────────
  var draining = false;
  function drainQueue() {
    if (draining) return;
    draining = true;
    KioskDB.getSyncableItems().then(function (items) {
      return items.reduce(function (chain, item) {
        return chain.then(function () { return syncItem(item); });
      }, Promise.resolve());
    }).catch(function () {}).then(function () { draining = false; });
  }

  function syncItem(item) {
    var fd = new FormData();
    fd.append('client_uuid', item.clientUuid);
    fd.append('index_number', item.indexNumber);
    var data = item.formData || {};
    Object.keys(data).forEach(function (k) {
      var v = data[k];
      if (v === false) return;
      if (v === true) v = '1';
      fd.append(k, v == null ? '' : v);
    });
    if (item.photoBlob) {
      fd.append('passport_photo', item.photoBlob, (item.photoMeta && item.photoMeta.name) || 'photo.jpg');
    }

    return KioskDB.updateQueueItem(item.clientUuid, { state: 'uploading' }).then(function () {
      return fetch('/sync-kiosk.php', { method: 'POST', body: fd });
    }).then(function (r) { return r.json(); }).then(function (resp) {
      if (resp.status === 'success' || (resp.status === 'conflict' && resp.reason === 'already_registered')) {
        return KioskDB.deleteQueueItem(item.clientUuid).then(function () {
          // Show admission number banner if still on success/any screen
          if (resp.admission_number) {
            var el = document.getElementById('kSyncedAdmission');
            if (el) { el.textContent = resp.admission_number; el.closest('.k-synced-banner') && (el.closest('.k-synced-banner').style.display = 'flex'); }
          }
        });
      }
      var attempts = (item.attempts || 0) + 1;
      var backoff  = KioskDB.BACKOFF_MS[Math.min(attempts, KioskDB.BACKOFF_MS.length - 1)];
      return KioskDB.updateQueueItem(item.clientUuid, {
        state: attempts >= KioskDB.MAX_ATTEMPTS ? 'failed' : 'retry',
        attempts: attempts,
        nextRetryAt: Date.now() + backoff,
        lastError: resp.message || resp.reason || 'Unknown server response',
      });
    }).catch(function () {
      var attempts = (item.attempts || 0) + 1;
      var backoff  = KioskDB.BACKOFF_MS[Math.min(attempts, KioskDB.BACKOFF_MS.length - 1)];
      return KioskDB.updateQueueItem(item.clientUuid, {
        state: attempts >= KioskDB.MAX_ATTEMPTS ? 'failed' : 'retry',
        attempts: attempts,
        nextRetryAt: Date.now() + backoff,
        lastError: 'Network error — will retry.',
      });
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    applySchoolMeta();
    initWelcomeScreen();
    initVerifyScreen();
    initFormAutoSave();
    initStepNavigation();
    initPhotoUpload();
    initSubmit();
    showScreen('welcome');

    // Drain any previously queued items on page load
    drainQueue();
    setInterval(drainQueue, 30000);
    window.addEventListener('online', drainQueue);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') drainQueue();
    });
  });

})();
