<?php if ( ! defined( 'ABSPATH' ) ) exit;
$me = wp_get_current_user();
?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="emails" data-me="<?php echo esc_attr( $me->user_email ); ?>">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Emails</h1><p class="ccm-sub">Choose who gets which Supply Ops emails, send a message, and see what was sent.</p></div>
  </div>

  <div class="ccm-tabs" role="tablist" aria-label="Email sections">
    <button type="button" class="ccm-tab on" role="tab" aria-selected="true" data-etab="recipients">Recipients <span class="ccm-count" id="em-count">–</span></button>
    <button type="button" class="ccm-tab" role="tab" aria-selected="false" data-etab="compose">Send email</button>
    <button type="button" class="ccm-tab" role="tab" aria-selected="false" data-etab="templates">Templates</button>
    <button type="button" class="ccm-tab" role="tab" aria-selected="false" data-etab="log">Sent log</button>
  </div>

  <!-- ============ RECIPIENTS ============ -->
  <section class="ccm-epanel" data-epanel="recipients" role="tabpanel">
    <form class="ccm-card ccm-em-add" id="em-add" novalidate>
      <div class="ccm-card-h" style="margin-bottom:12px">
        <div><h2 class="ccm-card-t">Add email addresses</h2><p class="ccm-card-s">Separate several addresses with commas. New addresses start with the order emails and “Vendor rejected order” ticked. Change them below, then save.</p></div>
      </div>
      <div class="ccm-em-addrow">
        <label class="ccm-field ccm-grow">Email addresses
          <input class="ccm-input" type="text" id="em-new" placeholder="alyshia@cannacabana.com, supply@cannacabana.com" autocomplete="off">
        </label>
        <label class="ccm-field fixed">Name (optional)
          <input class="ccm-input" type="text" id="em-newname" placeholder="e.g. Alyshia">
        </label>
        <button type="submit" class="ccm-btn primary">+ Add</button>
      </div>
      <div class="ccm-err" role="alert" id="em-add-err" hidden></div>
    </form>

    <div class="ccm-elistbar" id="em-listbar" hidden>
      <label class="ccm-field ccm-grow"><span class="ccm-sr">Search addresses</span>
        <input class="ccm-input" type="search" id="em-search" placeholder="Search addresses or names…" autocomplete="off">
      </label>
      <span class="ccm-muted ccm-small" id="em-listinfo"></span>
      <button type="button" class="ccm-btn sm" id="em-expand">Expand all</button>
    </div>

    <div id="em-list" class="ccm-elist" aria-live="polite"><div class="ccm-card ccm-empty">Loading recipients…</div></div>

    <div class="ccm-savebar" id="em-savebar">
      <span id="em-savestate" class="ccm-muted">All changes saved</span>
      <span class="ccm-actions">
        <button type="button" class="ccm-btn" id="em-discard" disabled>Discard</button>
        <button type="button" class="ccm-btn primary" id="em-save" disabled>Save changes</button>
      </span>
    </div>
  </section>

  <!-- ============ COMPOSE ============ -->
  <section class="ccm-epanel" data-epanel="compose" role="tabpanel" hidden>
    <form class="ccm-card ccm-compose" id="em-compose" novalidate>
      <div class="ccm-card-h" style="margin-bottom:12px">
        <div><h2 class="ccm-card-t">Send an email</h2><p class="ccm-card-s">Sent from Supply Manager with the Canna Cabana email layout. Each address gets its own copy.</p></div>
      </div>
      <label class="ccm-field">To
        <input class="ccm-input" type="text" id="em-to" placeholder="name@cannacabana.com, other@cannacabana.com" autocomplete="off">
      </label>
      <div class="ccm-chips" id="em-tochips" aria-label="Quick add"></div>
      <label class="ccm-field">Subject
        <input class="ccm-input" type="text" id="em-subject" maxlength="200" placeholder="e.g. November ordering window opens on the 1st">
      </label>
      <div class="ccm-field">
        <span id="em-msg-l">Message</span>
        <div class="ccm-editor">
          <div class="ccm-editor-bar" role="toolbar" aria-label="Formatting">
            <button type="button" class="ccm-btn sm icon" data-cmd="bold" aria-label="Bold" title="Bold"><strong>B</strong></button>
            <button type="button" class="ccm-btn sm icon" data-cmd="italic" aria-label="Italic" title="Italic"><em>I</em></button>
            <button type="button" class="ccm-btn sm icon" data-cmd="insertUnorderedList" aria-label="Bulleted list" title="Bulleted list"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg></button>
            <button type="button" class="ccm-btn sm icon" data-cmd="insertOrderedList" aria-label="Numbered list" title="Numbered list"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 6h11M10 12h11M10 18h11"/><path d="M4 6h1v4M4 10h2"/><path d="M4 14h2l-2 4h2"/></svg></button>
            <button type="button" class="ccm-btn sm icon" data-cmd="createLink" aria-label="Add link" title="Add link"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></button>
            <button type="button" class="ccm-btn sm" data-cmd="removeFormat" title="Clear formatting">Clear</button>
          </div>
          <div class="ccm-editor-area" id="em-body" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="em-msg-l" data-ph="Write your message…"></div>
        </div>
      </div>
      <div class="ccm-result" id="em-result" role="status" hidden></div>
      <div class="ccm-actions" style="justify-content:flex-end">
        <button type="button" class="ccm-btn" id="em-preview">Preview</button>
        <button type="submit" class="ccm-btn primary" id="em-send">Send email</button>
      </div>
    </form>
  </section>

  <!-- ============ TEMPLATES ============ -->
  <section class="ccm-epanel" data-epanel="templates" role="tabpanel" hidden>
    <div class="ccm-card">
      <div class="ccm-card-h">
        <div><h2 class="ccm-card-t">Notification templates</h2><p class="ccm-card-s">Every email uses the same branded layout. Previews and tests use your latest order — nothing real changes.</p></div>
        <form class="ccm-em-testall" id="em-testall" novalidate>
          <label class="ccm-field">Send tests to
            <input class="ccm-input" type="email" id="em-testto" value="<?php echo esc_attr( $me->user_email ); ?>">
          </label>
          <button type="submit" class="ccm-btn">Send all tests</button>
        </form>
      </div>
      <div id="em-tpls" class="ccm-etpls"></div>
    </div>
  </section>

  <!-- ============ LOG ============ -->
  <section class="ccm-epanel" data-epanel="log" role="tabpanel" hidden>
    <div class="ccm-tablewrap">
      <div class="ccm-toolbar">
        <label class="ccm-field grow">Search
          <input class="ccm-input" type="search" placeholder="Recipient or subject…" id="em-logq">
        </label>
        <button type="button" class="ccm-btn" id="em-logrefresh">Refresh</button>
      </div>
      <table class="ccm-table">
        <thead><tr><th>Sent</th><th>To</th><th>Subject</th><th>Type</th><th class="num">Email</th></tr></thead>
        <tbody id="em-logrows"><tr><td colspan="5" class="ccm-empty">Loading…</td></tr></tbody>
      </table>
      <div class="ccm-listfoot"><span id="em-logfoot"></span><span class="ccm-actions"><button type="button" class="ccm-btn sm" id="em-logprev">← Newer</button><button type="button" class="ccm-btn sm" id="em-lognext">Older →</button></span></div>
    </div>
  </section>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
