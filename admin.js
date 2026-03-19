/* Spielbank Events – Admin v3 */
.sbe-wrap{max-width:1400px}
.sbe-page-title{display:flex;align-items:center;gap:8px;font-size:22px;margin-bottom:16px;color:#1d2327}
.sbe-page-title .dashicons{font-size:26px;color:#2e7d32}
.sbe-shortcode-hint{margin-left:auto;font-size:12px;color:#666;font-weight:400}
.sbe-shortcode-hint code{background:#f0f0f0;padding:2px 6px;border-radius:3px}

/* Stats */
.sbe-stats{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.sbe-stat{background:#fff;border:1px solid #ddd;border-radius:6px;padding:10px 18px;display:flex;flex-direction:column;align-items:center;min-width:85px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.sbe-stat strong{font-size:22px;font-weight:700;line-height:1;color:#1d2327}
.sbe-stat span{font-size:10px;color:#888;margin-top:3px;text-align:center}
.sbe-stat--scraped strong{color:#d46b00}
.sbe-stat--approved strong{color:#0073aa}
.sbe-stat--published strong{color:#2e7d32}
.sbe-stat--locs strong{color:#6a1b9a}
.sbe-stat--sources strong{color:#00695c}

/* Toolbars */
.sbe-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;margin-bottom:10px}
.sbe-toolbar--sub{background:#fafafa;border-radius:0;border-left:none;border-right:none;border-top:none}
.sbe-btn-scrape{background:#2e7d32!important;border-color:#1b5e20!important;color:#fff!important}
.sbe-btn-scrape:hover{background:#1b5e20!important}
.sbe-btn-publish{background:#0073aa!important;border-color:#005d8c!important;color:#fff!important}
.sbe-btn-approve{background:#e8f5e9!important;border-color:#a5d6a7!important;color:#2e7d32!important}
.sbe-btn-add{background:#f5f5f5!important}
.sbe-btn-danger{background:#d32f2f!important;border-color:#b71c1c!important;color:#fff!important}
.sbe-toolbar .dashicons{font-size:14px;line-height:24px;margin-right:2px;vertical-align:middle}

/* Log */
.sbe-log{background:#1d2327;color:#a8d5a2;border-radius:6px;margin-bottom:12px;overflow:hidden}
.sbe-log-header{display:flex;justify-content:space-between;align-items:center;padding:7px 14px;background:#2c3338;color:#ccc;font-size:13px}
.sbe-log-close{background:none;border:none;color:#aaa;cursor:pointer;font-size:16px;padding:0}
.sbe-log pre{padding:12px 14px;margin:0;font-size:12px;line-height:1.7;white-space:pre-wrap;max-height:220px;overflow-y:auto}

/* Progress bar */
.sbe-progress-wrap{background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;margin-bottom:10px}
.sbe-progress-info{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:13px;color:#1d2327}
.sbe-progress-info #sbe-progress-label{font-weight:500}
.sbe-progress-info #sbe-progress-pct{font-weight:700;color:#2e7d32;font-size:14px}
.sbe-progress-bar{background:#e0e0e0;border-radius:4px;height:10px;overflow:hidden}
.sbe-progress-fill{background:linear-gradient(90deg,#2e7d32,#43a047);height:100%;border-radius:4px;transition:width .4s ease}

/* Notice */
.sbe-notice{padding:10px 14px;border-radius:5px;margin-bottom:10px;font-size:13px;font-weight:500}
.sbe-notice--success{background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20}
.sbe-notice--error{background:#ffebee;border:1px solid #ef9a9a;color:#b71c1c}

/* Tabs */
.sbe-tabs-nav{display:flex;gap:0;border-bottom:2px solid #e0e0e0;margin-bottom:0}
.sbe-tab-btn{background:#f5f5f5;border:1px solid #e0e0e0;border-bottom:none;padding:8px 18px;font-size:13px;cursor:pointer;color:#555;transition:all .15s;display:flex;align-items:center;gap:5px;border-radius:4px 4px 0 0}
.sbe-tab-btn .dashicons{font-size:14px;line-height:20px}
.sbe-tab-btn.active{background:#fff;color:#1d2327;font-weight:600;border-color:#e0e0e0;border-bottom-color:#fff;margin-bottom:-2px}
.sbe-tab-panel{background:#fff;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 6px 6px}

/* Schema legend */
.sbe-schema-legend{padding:10px 14px;background:#f8f4ff;border-bottom:1px solid #e0e0e0;font-size:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sbe-schema-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;background:#e3f2fd;color:#0d47a1}
.sbe-schema-badge--gambling{background:#f3e5f5;color:#6a1b9a}
.sbe-schema-badge--hours{background:#e8f5e9;color:#1b5e20}
.sbe-schema-badge--event{background:#fff3e0;color:#e65100}
.sbe-schema-badge--offer{background:#fce4ec;color:#880e4f}

/* Filters */
.sbe-toolbar--sub select,.sbe-toolbar--sub input{height:30px;font-size:12px;border:1px solid #ccc;border-radius:3px;padding:0 7px}

/* Table */
.sbe-table-wrap{overflow-x:auto}
.sbe-table{width:100%;border-collapse:collapse;font-size:13px}
.sbe-table thead{background:#f8f9fa}
.sbe-table th{padding:9px 12px;text-align:left;font-weight:600;color:#1d2327;border-bottom:2px solid #e0e0e0;white-space:nowrap}
.sbe-table td{padding:8px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.sbe-table tr:last-child td{border-bottom:none}
.sbe-table tr:hover td{background:#f9f9f9}
.sbe-col-name{max-width:260px}.sbe-col-name strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px}
.sbe-col-name small{color:#aaa;font-size:10px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px}
.sbe-col-source{font-size:11px;color:#aaa;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sbe-col-actions{white-space:nowrap}
.sbe-col-actions .button{margin-right:3px!important;padding:2px 7px!important;height:auto!important;font-size:11px!important}
.sbe-btn-approve-row{color:#2e7d32!important;border-color:#a5d6a7!important}
.sbe-btn-delete-event,.sbe-btn-delete-location{color:#d32f2f!important;border-color:#ef9a9a!important}
.sbe-hours-count{font-size:11px;color:#2e7d32;font-weight:600}

/* Status badges */
.sbe-status{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.sbe-status--scraped{background:#fff3e0;color:#e65100}
.sbe-status--approved{background:#e3f2fd;color:#0d47a1}
.sbe-status--published{background:#e8f5e9;color:#1b5e20}
.sbe-status--draft{background:#fafafa;color:#888;border:1px solid #e0e0e0}

/* Autodiscover progress bar (inside modal) */
.sbe-ad-progress-wrap{grid-column:1/-1;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:5px;padding:10px 14px;margin-bottom:4px}
.sbe-ad-progress-info{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;font-size:12px;color:#1d2327}
#sbe-ad-progress-label{font-weight:500}
#sbe-ad-progress-pct{font-weight:700;color:#00695c;font-size:13px}
.sbe-ad-progress-bar{background:#e0e0e0;border-radius:3px;height:8px;overflow:hidden}
.sbe-ad-progress-fill{background:linear-gradient(90deg,#00695c,#26a69a);height:100%;border-radius:3px;transition:width .4s ease}

/* Autodiscover log (inside modal) */
.sbe-input-with-btn{display:flex;gap:6px;align-items:flex-start}
.sbe-input-with-btn input{flex:1}
.sbe-input-with-btn .button{white-space:nowrap;height:32px;line-height:30px;padding:0 10px;font-size:12px}
.sbe-autodiscover-log{grid-column:1/-1;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:4px;padding:10px 12px;font-size:12px;line-height:1.6;max-height:150px;overflow-y:auto}
.sbe-autodiscover-log .sbe-ad-ok{color:#2e7d32}
.sbe-autodiscover-log .sbe-ad-warn{color:#d46b00}
.sbe-autodiscover-log .sbe-ad-info{color:#666}

/* Schema completeness bar */
.sbe-schema-bar{position:relative;background:#e0e0e0;border-radius:3px;height:16px;min-width:55px;overflow:hidden}
.sbe-schema-fill{height:100%;border-radius:3px;transition:width .3s ease}
.sbe-schema-pct{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;text-shadow:0 0 2px rgba(0,0,0,.4);mix-blend-mode:normal}

/* Source-specific */
.sbe-source-error{color:#d32f2f;cursor:help;font-size:14px;vertical-align:middle;margin-left:4px}

/* Batch bar */
.sbe-batch-bar{position:sticky;bottom:0;background:#2e7d32;color:#fff;padding:9px 16px;display:flex;align-items:center;gap:10px;border-radius:0 0 6px 6px}
.sbe-batch-bar span{font-weight:600;font-size:13px;margin-right:auto}
.sbe-batch-bar .button{background:rgba(255,255,255,.15)!important;color:#fff!important;border-color:rgba(255,255,255,.3)!important}

/* Modal */
.sbe-modal{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:20px}
.sbe-modal-inner{background:#fff;border-radius:8px;width:640px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.25)}
.sbe-modal-inner--wide{width:860px}
.sbe-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #e0e0e0;position:sticky;top:0;background:#fff;z-index:1}
.sbe-modal-header h2{margin:0;font-size:15px;color:#1d2327}
.sbe-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#888;padding:0;line-height:1}
.sbe-modal-close:hover{color:#333}
.sbe-modal-body{padding:16px 18px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
.sbe-modal-body--2col{grid-template-columns:1fr 1fr}
.sbe-modal-body--3col{grid-template-columns:1fr 1fr 1fr}
.sbe-modal-footer{padding:12px 18px;border-top:1px solid #e0e0e0;display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:#fff}

.sbe-form-section{grid-column:1/-1;padding:6px 0 2px;border-bottom:1px solid #f0f0f0;margin-bottom:4px}
.sbe-form-section h3{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#666;margin:0}
.sbe-form-section h3 small{font-weight:400;text-transform:none;letter-spacing:0;color:#999;font-size:11px}
.sbe-form-section--span{grid-column:1/-1}
.sbe-form-row{display:flex;flex-direction:column;gap:3px}
.sbe-form-row--full{grid-column:1/-1}
.sbe-form-row label{font-size:11px;font-weight:600;color:#444}
.sbe-form-row input,.sbe-form-row select,.sbe-form-row textarea{border:1px solid #ddd;border-radius:3px;padding:6px 9px;font-size:13px;color:#1d2327;outline:none;transition:border-color .15s}
.sbe-form-row input:focus,.sbe-form-row select:focus,.sbe-form-row textarea:focus{border-color:#2e7d32;box-shadow:0 0 0 2px rgba(46,125,50,.1)}

/* Hours editor inside modal */
.sbe-hours-table{width:100%;border-collapse:collapse;font-size:12px}
.sbe-hours-table th{padding:5px 8px;background:#f5f5f5;font-weight:600;color:#555;border-bottom:2px solid #ddd;text-align:left}
.sbe-hours-table td{padding:4px 6px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.sbe-hours-table input[type=time]{border:1px solid #ddd;border-radius:3px;padding:3px 6px;font-size:12px;width:90px}
.sbe-hours-table input[type=text]{border:1px solid #ddd;border-radius:3px;padding:3px 6px;font-size:12px;width:120px}
.sbe-hours-table .sbe-day-label{font-weight:600;color:#1d2327;width:80px}
.sbe-hours-add-row{display:flex;gap:8px;align-items:center;margin-top:6px}
.sbe-hours-add-row select,.sbe-hours-add-row input{height:28px;font-size:12px;border:1px solid #ccc;border-radius:3px;padding:0 6px}

/* Settings tab */
.sbe-settings-wrap{padding:20px 24px}

/* External events tab */
.sbe-ext-progress{padding:14px 16px;text-align:center;color:#555;font-size:13px;font-weight:500}
.sbe-ext-log{padding:8px 14px;background:#f8f9fa;border-bottom:1px solid #e0e0e0;font-size:12px;display:flex;gap:12px;flex-wrap:wrap}
.sbe-ext-source{display:inline-block;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:600;background:#e3f2fd;color:#0d47a1}
.sbe-ext-empty{padding:30px;text-align:center;color:#888;font-size:14px}
.sbe-ext-empty p{margin:0}

/* AI Assistent tab */
.sbe-ai-wrap{padding:24px 28px;max-width:800px}
.sbe-ai-wrap h2{font-size:16px;color:#1d2327;margin:0 0 8px}
.sbe-ai-wrap .description{color:#888;font-size:13px;margin:0 0 14px;line-height:1.5}
.sbe-ai-options{display:flex;gap:16px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.sbe-ai-options label{font-size:13px;color:#555;display:flex;align-items:center;gap:6px}
.sbe-ai-options select{height:30px;font-size:12px;border:1px solid #ccc;border-radius:3px;padding:0 7px}
.sbe-ai-export-info{margin:10px 0;font-size:13px}
.sbe-ai-upload{display:flex;gap:10px;align-items:center;margin:14px 0}
.sbe-ai-upload input[type=file]{font-size:13px}
.sbe-ai-import-preview{padding:10px 14px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:4px;font-size:13px;margin:10px 0}
.sbe-ai-import-result{padding:10px 14px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:4px;font-size:13px;margin:10px 0}
.sbe-ai-import-result ul{padding-left:18px;margin:0}
.sbe-ai-howto{background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px;padding:18px 22px}
.sbe-ai-howto ol{padding-left:20px;font-size:13px;line-height:1.7;color:#444}
.sbe-ai-howto ol li{margin-bottom:6px}
.sbe-ai-howto em{background:#fff3e0;padding:2px 6px;border-radius:3px;font-size:12px;color:#e65100}
.sbe-settings-wrap h2{margin:0 0 6px;font-size:18px;color:#1d2327}
.sbe-settings-wrap .description{color:#888;font-size:12px;margin-top:2px}
.sbe-settings-table{margin-top:16px}
.sbe-settings-table th{font-weight:600;font-size:13px;color:#1d2327;padding:12px 12px 12px 0;width:240px;vertical-align:top}
.sbe-settings-table td{padding:10px 0}
.sbe-settings-table input[type=text],.sbe-settings-table input[type=number]{border:1px solid #ddd;border-radius:3px;padding:6px 9px;font-size:13px}
.sbe-settings-table .small-text{width:70px}
.sbe-settings-table .regular-text{width:300px}
.sbe-settings-table .large-text{width:100%;max-width:560px}
.sbe-settings-table label{font-size:13px;color:#1d2327}

/* Spin */
@keyframes sbe-spin{to{transform:rotate(360deg)}}
.sbe-spinning{display:inline-block;animation:sbe-spin .8s linear infinite}

/* ══════════════════════════════════════════════════════════════════════════════
   WIZARD: Quick-Add Source
   ══════════════════════════════════════════════════════════════════════════════ */

.sbe-wizard-step { padding: 24px 32px; }
.sbe-wizard-hero { text-align: center; padding: 24px 0 16px; }
.sbe-wizard-hero h3 { font-size: 20px; margin: 0 0 8px; color: #1d2327; }
.sbe-wizard-hero .description { font-size: 14px; color: #646970; margin: 0; }

.sbe-wizard-url-input {
  display: flex; gap: 12px; align-items: center;
  max-width: 640px; margin: 20px auto 12px;
}
.sbe-wizard-url-input input {
  flex: 1; font-size: 16px; padding: 10px 14px;
  border: 2px solid #c3c4c7; border-radius: 6px;
}
.sbe-wizard-url-input input:focus {
  border-color: #0073aa; box-shadow: 0 0 0 1px #0073aa; outline: none;
}
.sbe-wizard-url-input .button-hero {
  white-space: nowrap; display: flex; align-items: center; gap: 6px;
  padding: 8px 20px; font-size: 14px;
}
.sbe-wizard-hint {
  text-align: center; font-size: 12px; color: #888; margin-top: 4px;
}

/* Progress steps */
.sbe-wizard-progress { margin: 16px 0; }
.sbe-wizard-progress-steps {
  display: flex; gap: 0; justify-content: center;
  background: #f6f7f7; border-radius: 8px; padding: 8px 4px;
}
.sbe-wiz-pstep {
  flex: 1; text-align: center; padding: 8px 12px;
  font-size: 13px; color: #888; transition: all 0.3s;
  border-radius: 6px;
}
.sbe-wiz-pstep.active { color: #0073aa; font-weight: 600; background: #e8f0fe; }
.sbe-wiz-pstep.done { color: #2e7d32; }
.sbe-wiz-pstep.done .sbe-wiz-pstep-icon::after { content: ''; }
.sbe-wiz-pstep.failed { color: #d32f2f; }

/* Scan header */
.sbe-wizard-scan-header { margin-bottom: 16px; }
.sbe-wizard-scan-header h3 { margin: 0 0 4px; font-size: 18px; }
.sbe-wizard-found-url { font-size: 12px; color: #0073aa; }

/* Result cards */
.sbe-wizard-results { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0; }
.sbe-wiz-card {
  border: 1px solid #dcdcde; border-radius: 8px; overflow: hidden;
  background: #fff; transition: box-shadow 0.2s;
}
.sbe-wiz-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.sbe-wiz-card-header {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; background: #f6f7f7; border-bottom: 1px solid #dcdcde;
  font-size: 13px;
}
.sbe-wiz-card-header .dashicons { font-size: 16px; width: 16px; height: 16px; color: #0073aa; }
.sbe-wiz-card-badge {
  margin-left: auto; background: #0073aa; color: #fff;
  font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600;
}
.sbe-wiz-card-badge.badge-warn { background: #d46b00; }
.sbe-wiz-card-badge.badge-none { background: #888; }
.sbe-wiz-card-body { padding: 12px 14px; font-size: 12px; max-height: 200px; overflow-y: auto; }
.sbe-wiz-card-body p { margin: 4px 0; }
.sbe-wiz-empty { color: #888; font-style: italic; }

/* URL list with checkboxes in wizard */
.sbe-wiz-url-item {
  display: flex; align-items: center; gap: 8px;
  padding: 4px 0; border-bottom: 1px solid #f0f0f0;
}
.sbe-wiz-url-item:last-child { border-bottom: none; }
.sbe-wiz-url-item input[type="checkbox"] { margin: 0; flex-shrink: 0; }
.sbe-wiz-url-item label {
  flex: 1; font-size: 12px; color: #2c3338;
  word-break: break-all; cursor: pointer;
}
.sbe-wiz-url-item .sbe-wiz-url-tag {
  font-size: 10px; padding: 1px 6px; border-radius: 3px;
  background: #e8f0fe; color: #0073aa; white-space: nowrap;
}

/* Seed preview key-value */
.sbe-wiz-kv { display: flex; gap: 8px; padding: 3px 0; }
.sbe-wiz-kv-key { font-weight: 600; color: #50575e; min-width: 80px; flex-shrink: 0; }
.sbe-wiz-kv-val { color: #2c3338; word-break: break-all; }

/* Log details */
.sbe-wizard-log-details { margin: 12px 0; font-size: 12px; }
.sbe-wizard-log-details summary {
  cursor: pointer; color: #646970; padding: 4px 0;
  user-select: none;
}
.sbe-wizard-log-details pre {
  background: #f6f7f7; padding: 10px 14px; border-radius: 6px;
  font-size: 11px; line-height: 1.5; max-height: 200px; overflow-y: auto;
  margin-top: 8px;
}

/* Step 2 actions */
.sbe-wizard-step2-actions {
  display: flex; gap: 10px; justify-content: flex-end;
  padding-top: 16px; border-top: 1px solid #dcdcde;
}
.sbe-wizard-step2-actions .button:first-child { margin-right: auto; }

/* Responsive */
@media (max-width: 782px) {
  .sbe-wizard-results { grid-template-columns: 1fr; }
  .sbe-wizard-url-input { flex-direction: column; }
}
