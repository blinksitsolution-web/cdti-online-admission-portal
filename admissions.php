<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
startSecureSession();
emitCspHeader();

$error    = getFlash('error');
$showSearch = getFlash('show_search');
$s        = getSettings();
$helpline = $s['helpline_number'] ?? '+233 0552290973';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admissions | <?= htmlspecialchars($s['school_name'] ?? 'KIMTECH') ?></title>
  <meta name="description" content="Enter your CSSPS index number to begin your online admission registration.">
  <link rel="icon" type="image/png" href="assets/img/logo.png">
  <link rel="stylesheet" href="assets/css/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/css/uikit.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <script nonce="<?= generateCspNonce() ?>">
    function disableBack() { window.history.forward(); }
    setTimeout(disableBack, 0);
    window.onunload = function() { null; };
  </script>
</head>
<body>

<!-- NAVBAR -->
<div data-uk-sticky="sel-target: .uk-navbar-container; cls-active: uk-navbar-sticky">
  <nav class="uk-navbar-container uk-margin uk-light">
    <div class="uk-container">
      <div data-uk-navbar>
        <div class="uk-navbar-left">
          <img src="assets/img/logo.png" width="45" height="45" alt="Logo" style="margin-left:10px;">
          <a class="uk-navbar-item uk-logo" href="index" style="margin-left:-4px;font-size:1.1rem;font-weight:700;">
            <?= htmlspecialchars($s['school_name'] ?? 'KIMTECH') ?>
          </a>
        </div>
        <div class="uk-navbar-center uk-visible@m">
          <ul class="uk-navbar-nav">
            <li><a href="index" style="text-transform:uppercase;">Home</a></li>
            <li class="uk-active"><a href="admissions" style="text-transform:uppercase;">Admissions</a></li>
          </ul>
        </div>
        <div class="uk-navbar-right">
          <a class="uk-navbar-toggle uk-hidden@m" href="#offcanvas" data-uk-navbar-toggle-icon data-uk-toggle></a>
        </div>
      </div>
    </div>
  </nav>
</div>

<!-- MAIN CONTENT -->
<section style="margin-top:80px;padding-bottom:60px;position:relative;z-index:1;">
  <div class="uk-container" style="max-width:480px;">
    <div class="portal-card">

      <!-- IMPORTANT NOTICE -->
      <div class="notice-section">
        <h2><i class="fa-solid fa-triangle-exclamation"></i> VERY IMPORTANT NOTICE</h2>
        <ul>
          <li>Please ensure that you have printed your <strong>CSSPS placement form</strong></li>
          <li>Your <strong>enrolment code</strong>, found on your <strong style="color:#f4a261;">PLACEMENT FORM</strong>, is REQUIRED</li>
          <li>Your admission is <strong>Incomplete</strong> without your <strong>Enrolment Code</strong></li>
        </ul>
      </div>

      <!-- INSTRUCTIONS -->
      <div class="notice-section" style="border-left-color:#0097d6;">
        <h2 style="color:#0097d6;"><i class="fa-solid fa-clipboard-list"></i> ADMISSION INSTRUCTIONS</h2>
        <p style="color:#ccc;font-size:0.82rem;margin-bottom:0.5rem;">Parents are to make available the following documents when reporting to school:</p>
        <ol style="color:#ddd;font-size:0.85rem;padding-left:1.25rem;line-height:2;">
          <li>Placement form (1 original + 1 photocopy)</li>
          <li>Personal Record form</li>
          <li>Admission Letter (2 copies)</li>
          <li>Birth Certificate / Baptismal / Ghana Card</li>
          <li>2 Passport Pictures</li>
        </ol>
      </div>

      <!-- ERROR -->
      <?php if ($error): ?>
      <div class="alert-error">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
        &nbsp;&nbsp;
        <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#searchModal"
                style="border-radius:20px;font-size:0.78rem;white-space:nowrap;">
          <i class="fa-solid fa-magnifying-glass"></i> Search here...
        </button>
      </div>
      <?php endif; ?>

      <!-- LOGIN FORM -->
      <form method="POST" action="autenticate" autocomplete="off" id="loginForm">
        <?= csrfField() ?>
        <div class="uk-margin">
          <label class="uk-form-label">Index Number (e.g. 41700600925)</label>
          <div class="uk-form-controls">
            <input class="uk-input uk-border-rounded" type="text" name="index_no" id="index_no"
                   placeholder="Enter your CSSPS index number"
                   maxlength="20" required autocomplete="off" inputmode="numeric">
          </div>
        </div>
        <div class="uk-text-center" style="margin-top:1.5rem;">
          <button type="submit" class="tm-button">Proceed <i class="fa-solid fa-arrow-right"></i></button>
          <p class="helpline"><i class="fa-solid fa-phone"></i> HELPLINE: <?= htmlspecialchars($helpline) ?></p>
        </div>
      </form>

    </div><!-- /portal-card -->
  </div>
</section>

<!-- ── SEARCH MODAL (Unlisted Students) ──────────────────────────── -->
<div class="modal fade" id="searchModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content" style="background:#0f1e2d;border:1px solid rgba(0,111,160,0.4);">
      <div class="modal-header">
        <h5 class="modal-title" style="color:#fff;"><i class="fa-solid fa-magnifying-glass"></i> Search for Your Name</h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
      </div>
      <form method="POST" action="save_nf" autocomplete="off" id="searchForm">
        <?= csrfField() ?>
        <div class="modal-body">
          <p style="color:#ccc;font-size:0.85rem;margin-bottom:1rem;">
            Type your full name to find your placement record. Compare masked details with your placement form to confirm identity.
          </p>
          <div class="form-group">
            <input type="text" id="nameSearch" class="form-control"
                   placeholder="Start typing your full name..." autocomplete="off"
                   style="background:rgba(255,255,255,0.08);border:1px solid rgba(0,111,160,0.4);color:#fff;">
            <div id="suggestions" class="autocomplete-suggestions" style="display:none;"></div>
          </div>

          <div id="selectedInfo" class="hidden" style="background:rgba(0,111,160,0.1);border:1px solid rgba(0,111,160,0.3);border-radius:8px;padding:0.75rem;margin-bottom:1rem;">
            <p style="color:#ccc;font-size:0.82rem;margin:0;" id="selectedText"></p>
          </div>

          <div class="form-group hidden" id="indexGroup">
            <label style="color:#ccc;font-size:0.82rem;">Enter Your CSSPS Index Number</label>
            <input type="text" class="form-control" id="index_number" name="index_number"
                   placeholder="Your index number" maxlength="20" inputmode="numeric"
                   style="background:rgba(255,255,255,0.08);border:1px solid rgba(0,111,160,0.4);color:#fff;">
          </div>
          <div class="form-group hidden" id="codeGroup">
            <label style="color:#ccc;font-size:0.82rem;">Enter Your Enrolment Code (from placement form)</label>
            <input type="number" class="form-control" id="user_code" name="usercode"
                   placeholder="Your enrolment code"
                   style="background:rgba(255,255,255,0.08);border:1px solid rgba(0,111,160,0.4);color:#fff;">
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,0.1);">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" id="searchSubmitBtn" name="btnAdd" disabled>Continue <i class="fa-solid fa-arrow-right"></i></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer style="position:relative;z-index:1;border-top:1px solid rgba(255,255,255,0.06);padding:1.25rem;text-align:center;">
  <p style="color:rgba(255,255,255,0.35);font-size:0.78rem;margin:0;">
    &copy; <?= date('Y') ?> <?= htmlspecialchars($s['school_name'] ?? 'KIMTECH') ?> &mdash; All Rights Reserved.<br>
    <small>Built by <a href="#" style="color:#4dd8ff;text-decoration:none;">Blinks I.T. Solution</a></small>
  </p>
</footer>

<!-- OFFCANVAS -->
<div id="offcanvas" data-uk-offcanvas="flip:true;overlay:true">
  <div class="uk-offcanvas-bar">
    <button class="uk-offcanvas-close" type="button" data-uk-close></button>
    <ul class="uk-nav uk-nav-default" style="margin-top:2rem;">
      <li><a href="index"><i class="fa-solid fa-house"></i> Home</a></li>
      <li><a href="admissions"><i class="fa-solid fa-graduation-cap"></i> Admissions</a></li>
    </ul>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/js/uikit-icons.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<?php if ($showSearch): ?>
<script nonce="<?= generateCspNonce() ?>">$(function(){ $('#searchModal').modal('show'); });</script>
<?php endif; ?>

<script nonce="<?= generateCspNonce() ?>">
function validateIndex() {
  const v = document.getElementById('index_no').value.trim();
  if (v.length < 6 || v.length > 20 || !/^[A-Za-z0-9]+$/.test(v)) {
    Swal.fire({ icon:'error', title:'Invalid Index Number', text:'Please enter a valid CSSPS index number (6–20 digits).', background:'#0f1e2d', color:'#fff' });
    return false;
  }
  return true;
}

// AJAX autocomplete — passes CSRF token so the secured search endpoint accepts the request
const _csrfToken = <?= json_encode(generateCsrfToken()) ?>;
let selectedName = '';
$('#nameSearch').on('input', function() {
  const q = $(this).val().trim();
  if (q.length < 2) {
    $('#suggestions').hide().html('');
    $('#indexGroup,#codeGroup,#selectedInfo').addClass('hidden');
    $('#searchSubmitBtn').prop('disabled', true);
    return;
  }
  $.getJSON('search.php', { query: q, csrf_token: _csrfToken }, function(data) {
    let html = '';
    if (data.length) {
      data.forEach(function(s) {
        html += `<div class="autocomplete-suggestion" data-name="${s.full_name}" data-info="${s.info}">
          <strong>${s.full_name}</strong>
          <span class="badge-pill">${s.program}</span>
          <span class="badge-pill" style="background:#555;">${s.residency}</span>
          <span class="badge-pill" style="background:#333;">${s.gender}</span>
        </div>`;
      });
      $('#suggestions').html(html).show();
    } else {
      $('#suggestions').html('<div class="autocomplete-suggestion" style="color:#999;">No results found</div>').show();
    }
  });
});

$(document).on('click', '.autocomplete-suggestion', function() {
  selectedName = $(this).data('name') || '';
  const info   = $(this).data('info') || '';
  if (!selectedName) return;
  $('#nameSearch').val(selectedName);
  $('#suggestions').hide().html('');
  $('#selectedInfo').removeClass('hidden');
  $('#selectedText').text('Selected: ' + selectedName + (info ? ' — ' + info : ''));
  $('#indexGroup,#codeGroup').removeClass('hidden');
  $('#searchSubmitBtn').prop('disabled', false);
});

$(document).on('click', function(e) {
  if (!$(e.target).closest('#nameSearch,#suggestions').length) {
    $('#suggestions').hide();
  }
});

// Wired up here instead of inline onsubmit/oninput attributes, which CSP's
// nonce'd script-src always blocks regardless of nonce placement.
document.getElementById('loginForm').addEventListener('submit', function (e) {
  if (!validateIndex()) e.preventDefault();
});
document.getElementById('index_no').addEventListener('input', function () {
  this.value = this.value.replace(/[^A-Za-z0-9]/g, '').slice(0, 20);
});
</script>
</body>
</html>
