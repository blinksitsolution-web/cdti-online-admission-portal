/**
 * Background sync engine for the offline registration queue (Req. 5, 8, 9).
 *
 * Drains OfflineDB's submission_queue whenever the browser looks reachable
 * (online event, page load, tab becoming visible, or a periodic safety-net
 * timer — real Background Sync isn't available in every target browser, e.g.
 * Safari/iOS, so these in-page triggers are the primary mechanism).
 *
 * State machine per item: Pending -> Uploading -> Retry -> Completed ->
 * Archived, with a Failed state reached from Retry after the attempt cap or
 * immediately on a non-recoverable server response (validation rejected, a
 * conflict the student must act on). Retry uses exponential backoff
 * (5s -> 15s -> 45s -> 2m -> 5m, capped) up to 10 attempts.
 *
 * Never touches the plain online submit path — register.php's normal HTML
 * form POST is completely untouched by this file.
 */
(function () {
  'use strict';

  if (typeof OfflineDB === 'undefined') return;

  var SYNC_ENDPOINT = '/register.php';
  var TOKEN_ENDPOINT = '/csrf_token';
  var BACKOFF_SCHEDULE_MS = [5000, 15000, 45000, 120000, 300000]; // 5s,15s,45s,2m,5m (capped)
  var MAX_ATTEMPTS = 10;
  var SAFETY_NET_INTERVAL_MS = 30000;

  var draining = false; // single-flight guard so concurrent triggers don't double-submit

  function nextBackoffMs(attempts) {
    var idx = Math.min(attempts, BACKOFF_SCHEDULE_MS.length - 1);
    return BACKOFF_SCHEDULE_MS[idx];
  }

  function notify(kind, item, message) {
    document.dispatchEvent(new CustomEvent('cdti:sync-status', {
      detail: { kind: kind, item: item, message: message || null },
    }));
  }

  function fetchFreshToken() {
    return fetch(TOKEN_ENDPOINT, { credentials: 'same-origin' }).then(function (res) {
      if (res.status === 401) {
        var err = new Error('session_expired');
        err.code = 'session_expired';
        throw err;
      }
      if (!res.ok) throw new Error('token_fetch_failed');
      return res.json();
    }).then(function (data) { return data.token; });
  }

  function buildFormData(item, token) {
    var fd = new FormData();
    fd.append('csrf_token', token);
    fd.append('sync', '1');
    fd.append('client_uuid', item.clientUuid);
    var formData = item.formData || {};
    Object.keys(formData).forEach(function (key) {
      var val = formData[key];
      if (val === false) return; // unchecked checkbox — omit, matches native form encoding
      if (val === true) val = '1';
      fd.append(key, val == null ? '' : val);
    });
    if (item.photoBlob) {
      fd.append('passport_photo', item.photoBlob, (item.photoMeta && item.photoMeta.name) || 'passport_photo.jpg');
    }
    return fd;
  }

  function scheduleRetry(item, message) {
    var attempts = (item.attempts || 0) + 1;
    if (attempts >= MAX_ATTEMPTS) {
      return OfflineDB.updateQueueItem(item.clientUuid, {
        state: 'failed',
        attempts: attempts,
        lastError: 'Could not submit after several attempts. Please check your connection and try again from the Review step.',
      }).then(function (updated) { notify('failed', updated); });
    }
    return OfflineDB.updateQueueItem(item.clientUuid, {
      state: 'retry',
      attempts: attempts,
      lastError: message || null,
      nextRetryAt: Date.now() + nextBackoffMs(attempts),
    }).then(function (updated) { notify('retry-scheduled', updated, message); });
  }

  function archiveItem(item, kind) {
    return OfflineDB.updateQueueItem(item.clientUuid, {
      state: 'archived',
      lastError: null,
      photoBlob: null, // reclaim storage — the photo has already been submitted
    }).then(function (updated) {
      return OfflineDB.deleteDraft(item.indexNumber).then(function () {
        notify(kind, updated);
      });
    });
  }

  function handleServerResponse(item, data) {
    if (data.status === 'success') {
      return archiveItem(item, 'completed');
    }

    if (data.status === 'conflict') {
      if (data.reason === 'house_unavailable') {
        return OfflineDB.updateQueueItem(item.clientUuid, {
          state: 'failed',
          lastError: data.message || 'The selected house is no longer available.',
        }).then(function (updated) { notify('house_unavailable', updated, data.message); });
      }
      if (data.reason === 'already_registered') {
        return archiveItem(item, 'already_registered');
      }
      if (data.reason === 'payment_required') {
        // Draft/queue item stays untouched — nothing lost, retried once payment clears.
        return scheduleRetry(item, data.message || 'Payment is required before this can be submitted.');
      }
      return scheduleRetry(item, data.message || 'Submission could not be completed yet.');
    }

    if (data.status === 'error' && data.reason === 'validation') {
      // Not recoverable by blind retry — the student needs to fix something.
      var firstMsg = (data.errors && data.errors[0] && data.errors[0].msg) || 'Some information needs to be corrected.';
      return OfflineDB.updateQueueItem(item.clientUuid, {
        state: 'failed',
        lastError: firstMsg,
      }).then(function (updated) { notify('validation_failed', updated, firstMsg); });
    }

    if (data.status === 'error' && data.reason === 'rate_limited') {
      return scheduleRetry(item, data.message || 'Too many attempts — will retry shortly.');
    }

    return scheduleRetry(item, 'Unexpected server response. Will retry automatically.');
  }

  function submitItem(item) {
    return fetchFreshToken().then(function (token) {
      return OfflineDB.updateQueueItem(item.clientUuid, { state: 'uploading' }).then(function () {
        return fetch(SYNC_ENDPOINT, {
          method: 'POST',
          body: buildFormData(item, token),
          credentials: 'same-origin',
        });
      });
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      return handleServerResponse(item, data);
    }).catch(function (err) {
      if (err && err.code === 'session_expired') {
        return OfflineDB.updateQueueItem(item.clientUuid, {
          state: 'retry',
          nextRetryAt: Date.now() + nextBackoffMs(item.attempts || 0),
          lastError: 'Your session has expired — please sign in again to finish syncing.',
        }).then(function (updated) { notify('needs-relogin', updated); });
      }
      // Network failure / server unreachable / bad JSON — transient, retry.
      return scheduleRetry(item, 'Could not reach the server. Will retry automatically.');
    });
  }

  // An item can be left in "uploading" only if the tab closed or crashed
  // mid-request in a previous session — this session never put it there.
  // Safe to requeue: the server's UUID idempotency check makes a duplicate
  // attempt a no-op even if the original request had actually gone through.
  function recoverStuckUploads() {
    return OfflineDB.getAllQueueItems().then(function (items) {
      var stuck = items.filter(function (i) { return i.state === 'uploading'; });
      return Promise.all(stuck.map(function (i) {
        return OfflineDB.updateQueueItem(i.clientUuid, { state: 'pending' });
      }));
    }).catch(function () {});
  }

  function drainQueue() {
    if (draining || !navigator.onLine) return Promise.resolve();
    draining = true;
    return OfflineDB.getSyncableSubmissions().then(function (items) {
      return items.reduce(function (chain, item) {
        return chain.then(function () { return submitItem(item); });
      }, Promise.resolve());
    }).catch(function () {
      // A single item's failure is already handled inside submitItem; this
      // only catches OfflineDB-level errors (e.g. IndexedDB unavailable).
    }).then(function () { draining = false; });
  }

  recoverStuckUploads().then(drainQueue);
  window.addEventListener('online', drainQueue);
  window.addEventListener('load', drainQueue);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') drainQueue();
  });
  setInterval(drainQueue, SAFETY_NET_INTERVAL_MS);

  window.CDTISyncEngine = { drainQueue: drainQueue };
})();
