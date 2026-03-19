/* Spielbank Events – Admin JS v3 */
jQuery(function($){
  var DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
  var DAYS_DE = {Monday:'Montag',Tuesday:'Dienstag',Wednesday:'Mittwoch',Thursday:'Donnerstag',Friday:'Freitag',Saturday:'Samstag',Sunday:'Sonntag'};

  // ── Tab switching ───────────────────────────────────────────────────────────
  $('.sbe-tab-btn').on('click', function(){
    var tab = $(this).data('tab');
    $('.sbe-tab-btn').removeClass('active');
    $(this).addClass('active');
    $('.sbe-tab-panel').hide();
    $('#sbe-tab-' + tab).show();
    // Update URL without reload
    var url = new URL(window.location);
    url.searchParams.set('sbe_tab', tab);
    history.replaceState(null, '', url);
  });

  // ══════════════════════════════════════════════════════════════════════════════
  // SCRAPE (sequential per source with progress)
  // ══════════════════════════════════════════════════════════════════════════════

  $('#sbe-btn-scrape').on('click', function(){
    var $btn = $(this).prop('disabled', true);
    $btn.find('.dashicons').addClass('sbe-spinning');
    $('#sbe-log').show();
    $('#sbe-log-content').text('');
    $('#sbe-progress-wrap').show();
    sbeSetProgress(0, 'Lade Quellen…');

    // Step 1: Get all active sources
    $.post(SBE.ajax_url, {action:'sbe_get_all_sources', nonce:SBE.nonce}, function(res){
      if (!res.success || !res.data.length) {
        $btn.prop('disabled', false).find('.dashicons').removeClass('sbe-spinning');
        sbeSetProgress(100, 'Keine aktiven Quellen gefunden.');
        sbeShowNotice('Keine aktiven Quellen zum Scrapen.', 'error');
        return;
      }

      var sources = res.data.filter(function(s){ return parseInt(s.is_active) === 1; });
      var total   = sources.length;
      var done    = 0;
      var allLog  = [];
      var totalEvents = 0;
      var totalLocs   = 0;
      var totalSkipped = 0;

      if (total === 0) {
        $btn.prop('disabled', false).find('.dashicons').removeClass('sbe-spinning');
        sbeSetProgress(100, 'Keine aktiven Quellen.');
        sbeShowNotice('Keine aktiven Quellen zum Scrapen.', 'error');
        return;
      }

      // Step 2: Scrape each source sequentially
      function scrapeNext(index) {
        if (index >= total) {
          // All done
          $btn.prop('disabled', false).find('.dashicons').removeClass('sbe-spinning');
          var msg = totalLocs + ' Standorte + ' + totalEvents + ' neue Events gespeichert (' + totalSkipped + ' übersprungen).';
          sbeSetProgress(100, '✓ Fertig! ' + msg);
          allLog.push('', '═══════════════════════════════', '✓ ' + msg);
          $('#sbe-log-content').text(allLog.join('\n'));
          sbeShowNotice(msg, 'success');
          sbeReloadEvents();
          sbeReloadLocations();
          sbeReloadSources();
          sbeUpdateTabCounts();
          return;
        }

        var src = sources[index];
        var pct = Math.round(((index) / total) * 100);
        sbeSetProgress(pct, 'Scrape ' + (index + 1) + '/' + total + ': ' + src.name + '…');

        $.post(SBE.ajax_url, {action:'sbe_scrape_single', nonce:SBE.nonce, source_id:src.id}, function(r){
          done++;
          if (r.success) {
            var d = r.data;
            allLog = allLog.concat(d.log);
            totalEvents  += d.events_saved || 0;
            totalLocs    += d.locs_saved || 0;
            totalSkipped += d.skipped || 0;
            // Update log live
            $('#sbe-log-content').text(allLog.join('\n'));
            // Scroll log to bottom
            var logEl = document.getElementById('sbe-log-content');
            if (logEl) logEl.scrollTop = logEl.scrollHeight;
          } else {
            allLog.push('✗ Fehler bei ' + src.name);
          }
          var newPct = Math.round((done / total) * 100);
          sbeSetProgress(newPct, 'Scrape ' + done + '/' + total + ' abgeschlossen…');
          scrapeNext(index + 1);
        }).fail(function(){
          done++;
          allLog.push('✗ Netzwerkfehler bei ' + src.name);
          scrapeNext(index + 1);
        });
      }

      scrapeNext(0);
    }).fail(function(){
      $btn.prop('disabled', false).find('.dashicons').removeClass('sbe-spinning');
      sbeShowNotice('Netzwerkfehler beim Laden der Quellen.', 'error');
    });
  });

  function sbeSetProgress(pct, label) {
    pct = Math.min(100, Math.max(0, pct));
    $('#sbe-progress-fill').css('width', pct + '%');
    $('#sbe-progress-pct').text(pct + '%');
    if (label) $('#sbe-progress-label').text(label);
  }

  // Update tab counts after operations
  function sbeUpdateTabCounts() {
    $.post(SBE.ajax_url, {action:'sbe_get_all_sources', nonce:SBE.nonce}, function(r){
      if (r.success) $('.sbe-tab-count-sources').text(r.data.length);
    });
    $.post(SBE.ajax_url, {action:'sbe_get_all_events', nonce:SBE.nonce}, function(r){
      if (r.success) $('.sbe-tab-count-events').text(r.data.length);
    });
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // SOURCES
  // ══════════════════════════════════════════════════════════════════════════════

  $('#sbe-btn-add-source').on('click', function(){ sbeOpenSourceModal({}); });

  $(document).on('click', '.sbe-btn-edit-source', function(){
    var src = $(this).data('src');
    sbeOpenSourceModal(typeof src === 'string' ? JSON.parse(src) : src);
  });

  $(document).on('click', '.sbe-btn-delete-source', function(){
    if (!confirm('Quelle wirklich löschen?')) return;
    $.post(SBE.ajax_url, {action:'sbe_delete_source', nonce:SBE.nonce, id:$(this).data('id')}, function(res){
      if (res.success) { sbeReloadSources(); sbeShowNotice('Quelle gelöscht.','success'); sbeUpdateTabCounts(); }
    });
  });

  window.sbeOpenSourceModal = function(s){
    $('#sbe-sf-id').val(s.id || '');
    $('#sbe-sf-name').val(s.name || '');
    $('#sbe-sf-baseurl').val(s.base_url || '');
    $('#sbe-sf-eventsurl').val(s.events_url || '');
    $('#sbe-sf-contacturl').val(s.contact_url || '');
    $('#sbe-sf-robotsurl').val(s.robots_url || '');
    $('#sbe-sf-sitemapurl').val(s.sitemap_url || '');
    $('#sbe-sf-active').val(s.is_active !== undefined ? s.is_active : 1);
    $('#sbe-sf-useragent').val(s.custom_user_agent || '');
    $('#sbe-sf-timeout').val(s.request_timeout || '');
    $('#sbe-sf-delay').val(s.request_delay || '');
    $('#sbe-sf-maxpages').val(s.max_pages || 1);
    $('#sbe-sf-pagination').val(s.follow_pagination || 0);
    $('#sbe-sf-pagsel').val(s.pagination_sel || '');
    $('#sbe-sf-eventsel').val(s.event_selectors || '');
    $('#sbe-sf-headers').val(s.custom_headers && s.custom_headers !== '{}' && s.custom_headers !== 'null' ? (typeof s.custom_headers === 'string' ? s.custom_headers : JSON.stringify(s.custom_headers, null, 2)) : '');
    $('#sbe-sf-seed').val(s.seed_data && s.seed_data !== '{}' && s.seed_data !== 'null' ? (typeof s.seed_data === 'string' ? s.seed_data : JSON.stringify(s.seed_data, null, 2)) : '');
    $('#sbe-sf-seedhours').val(s.seed_hours && s.seed_hours !== '[]' && s.seed_hours !== 'null' ? (typeof s.seed_hours === 'string' ? s.seed_hours : JSON.stringify(s.seed_hours)) : '');
    $('#sbe-modal-source-title').text(s.id ? 'Quelle bearbeiten' : 'Neue Quelle');
    $('#sbe-autodiscover-log').remove();
    $('#sbe-ad-progress-wrap').remove();
    $('#sbe-modal-source').show();
    $('#sbe-sf-name').focus();
  };

  window.sbeSaveSourceModal = function(){
    var data = {
      action: 'sbe_save_source', nonce: SBE.nonce,
      id:                $('#sbe-sf-id').val(),
      name:              $('#sbe-sf-name').val(),
      base_url:          $('#sbe-sf-baseurl').val(),
      events_url:        $('#sbe-sf-eventsurl').val(),
      contact_url:       $('#sbe-sf-contacturl').val(),
      robots_url:        $('#sbe-sf-robotsurl').val(),
      sitemap_url:       $('#sbe-sf-sitemapurl').val(),
      is_active:         $('#sbe-sf-active').val(),
      custom_user_agent: $('#sbe-sf-useragent').val(),
      request_timeout:   $('#sbe-sf-timeout').val(),
      request_delay:     $('#sbe-sf-delay').val(),
      max_pages:         $('#sbe-sf-maxpages').val(),
      follow_pagination: $('#sbe-sf-pagination').val(),
      pagination_sel:    $('#sbe-sf-pagsel').val(),
      event_selectors:   $('#sbe-sf-eventsel').val(),
      custom_headers:    $('#sbe-sf-headers').val() || '{}',
      seed_data:         $('#sbe-sf-seed').val() || '{}',
      seed_hours:        $('#sbe-sf-seedhours').val() || '[]',
    };
    if (!data.name.trim() || !data.base_url.trim()) { alert('Name und Base-URL sind erforderlich.'); return; }

    $.post(SBE.ajax_url, data, function(res){
      if (res.success) {
        sbeCloseModal('source');
        sbeShowNotice('Quelle gespeichert (ID: ' + res.data.id + ').', 'success');
        sbeReloadSources();
        sbeUpdateTabCounts();
      } else {
        sbeShowNotice('Fehler beim Speichern.', 'error');
      }
    });
  };

  // Test source URL
  $('#sbe-btn-test-source').on('click', function(){
    var raw = $('#sbe-sf-eventsurl').val() || '';
    var firstLine = raw.split(/\r?\n/).map(function(l){return l.trim();}).filter(Boolean)[0] || '';
    var url = firstLine || $('#sbe-sf-baseurl').val();
    if (!url) { alert('Bitte erst eine URL eingeben.'); return; }
    var $btn = $(this).prop('disabled', true).text('🔍 Teste…');
    $.post(SBE.ajax_url, {action:'sbe_test_source', nonce:SBE.nonce, url:url}, function(res){
      $btn.prop('disabled', false).text('🔍 URL testen');
      if (res.success) {
        var d = res.data;
        var msg = '✓ Erreichbar (' + d.bytes + ' Bytes)\n';
        msg += 'JSON-LD: ' + (d.has_jsonld ? '✓ vorhanden' : '✗ nicht gefunden') + '\n';
        msg += 'Events-Keywords: ' + (d.has_events ? '✓ gefunden' : '✗ nicht gefunden') + '\n\n';
        msg += 'Vorschau:\n' + d.preview;
        alert(msg);
      } else {
        alert('✗ ' + res.data);
      }
    }).fail(function(){ $btn.prop('disabled', false).text('🔍 URL testen'); alert('Netzwerkfehler.'); });
  });

  // Auto-discover sub-pages from base URL
  $(document).on('click', '#sbe-btn-autodiscover', function(){
    var baseUrl = $('#sbe-sf-baseurl').val();
    if (!baseUrl) { alert('Bitte erst eine Base-URL eingeben.'); return; }
    var $btn = $(this).prop('disabled', true);

    // Clean up
    $('#sbe-autodiscover-log').remove();
    $('#sbe-ad-progress-wrap').remove();

    // Insert progress bar at top of modal body
    var $modalBody = $('#sbe-modal-source .sbe-modal-body');
    $modalBody.prepend(
      '<div id="sbe-ad-progress-wrap" class="sbe-ad-progress-wrap">'
      +'<div class="sbe-ad-progress-info"><span id="sbe-ad-progress-label">Auto-Erkennung läuft…</span><span id="sbe-ad-progress-pct">0%</span></div>'
      +'<div class="sbe-ad-progress-bar"><div id="sbe-ad-progress-fill" class="sbe-ad-progress-fill" style="width:0%"></div></div>'
      +'</div>'
    );

    // Scroll modal to top
    $('#sbe-modal-source .sbe-modal-inner').scrollTop(0);

    // Simulate progress while waiting
    var fakePct = 0;
    var fakeTimer = setInterval(function(){
      fakePct += Math.random() * 12;
      if (fakePct > 90) fakePct = 90;
      var labels = ['robots.txt prüfen…','Sitemap parsen…','Homepage analysieren…','Event-Seiten suchen…','Kontakt-Seite suchen…'];
      var labelIdx = Math.min(Math.floor(fakePct / 20), labels.length - 1);
      $('#sbe-ad-progress-fill').css('width', Math.round(fakePct) + '%');
      $('#sbe-ad-progress-pct').text(Math.round(fakePct) + '%');
      $('#sbe-ad-progress-label').text(labels[labelIdx]);
    }, 800);

    $.ajax({
      url: SBE.ajax_url,
      type: 'POST',
      timeout: 120000,
      data: {action:'sbe_autodiscover', nonce:SBE.nonce, base_url:baseUrl, step:'all', state:'{}'},
      success: function(res){
        clearInterval(fakeTimer);
        $('#sbe-ad-progress-fill').css('width', '100%');
        $('#sbe-ad-progress-pct').text('100%');
        $('#sbe-ad-progress-label').text('✓ Fertig');
        $btn.prop('disabled', false);

        if (!res.success) {
          setTimeout(function(){
            $('#sbe-ad-progress-wrap').remove();
            $modalBody.prepend('<div id="sbe-autodiscover-log" class="sbe-autodiscover-log"><div class="sbe-ad-warn">⚠ ' + esc(res.data || 'Unbekannter Fehler') + '</div></div>');
          }, 500);
          return;
        }

        var d = res.data;
        var state = d.state || d; // support both step-based and direct response

        setTimeout(function(){
          $('#sbe-ad-progress-wrap').remove();

          // Show log
          var allLog = state.log || d.log || [];
          if (allLog.length) {
            var logHtml = '<div id="sbe-autodiscover-log" class="sbe-autodiscover-log">';
            allLog.forEach(function(line){
              var cls = line.charAt(0) === '✓' ? 'sbe-ad-ok' : (line.charAt(0) === '⚠' ? 'sbe-ad-warn' : 'sbe-ad-info');
              logHtml += '<div class="' + cls + '">' + esc(line) + '</div>';
            });
            logHtml += '</div>';
            $modalBody.prepend(logHtml);
          }

          // Auto-fill fields (only if currently empty) + highlight
          var filled = [];

          function fillField(selector, value, label) {
            if (value && !$(selector).val().trim()) {
              $(selector).val(value).css('background-color', '#e8f5e9');
              setTimeout(function(){ $(selector).css('background-color', ''); }, 3000);
              filled.push(label);
            }
          }

          fillField('#sbe-sf-name', state.name, 'Name');
          if (state.events_urls && state.events_urls.length > 0) {
            fillField('#sbe-sf-eventsurl', state.events_urls.join('\n'), state.events_urls.length + ' Events-URL(s)');
          }
          fillField('#sbe-sf-contacturl', state.contact_url, 'Kontakt-URL');
          fillField('#sbe-sf-sitemapurl', state.sitemap_url, 'Sitemap-URL');
          fillField('#sbe-sf-robotsurl', state.robots_url, 'robots.txt URL');

          if (state.seed_data && !$('#sbe-sf-seed').val().trim()) {
            $('#sbe-sf-seed').val(JSON.stringify(state.seed_data, null, 2)).css('background-color', '#e8f5e9');
            setTimeout(function(){ $('#sbe-sf-seed').css('background-color', ''); }, 3000);
            filled.push('Seed-Daten (JSON-LD)');
          }

          // Scroll modal to top to show results
          $('#sbe-modal-source .sbe-modal-inner').scrollTop(0);

          if (filled.length > 0) {
            sbeShowNotice('Auto-Erkennung: ' + filled.join(', ') + ' ausgefüllt.', 'success');
          } else {
            sbeShowNotice('Auto-Erkennung abgeschlossen – keine neuen Felder befüllt (bereits ausgefüllt oder nichts gefunden).', 'success');
          }
        }, 600);
      },
      error: function(xhr, status){
        clearInterval(fakeTimer);
        $btn.prop('disabled', false);
        $('#sbe-ad-progress-wrap').remove();
        var msg = status === 'timeout'
          ? '⚠ Timeout – die Seite antwortet zu langsam.'
          : '⚠ Netzwerkfehler bei der Auto-Erkennung.';
        $modalBody.prepend('<div id="sbe-autodiscover-log" class="sbe-autodiscover-log"><div class="sbe-ad-warn">' + msg + '</div></div>');
      }
    });
  });

  function sbeReloadSources(){
    $.post(SBE.ajax_url, {action:'sbe_get_all_sources', nonce:SBE.nonce}, function(res){
      if (!res.success) return;
      $('#sbe-source-tbody').html(res.data.map(sbeRenderSourceRow).join(''));
    });
  }

  function sbeRenderSourceRow(s){
    var j = JSON.stringify(s).replace(/"/g,'&quot;');
    var cls = parseInt(s.is_active) ? 'sbe-status--published' : 'sbe-status--draft';
    var lbl = parseInt(s.is_active) ? 'Aktiv' : 'Inaktiv';
    var scraped = s.last_scraped ? sbeFormatDate(s.last_scraped) : '–';
    var err = s.last_error ? '<span class="sbe-source-error" title="'+esc(s.last_error)+'">⚠</span>' : '';
    var evLines = s.events_url ? s.events_url.split(/\r?\n/).filter(function(l){return l.trim();}) : [];
    var evUrl = evLines.length > 0 ? '<small title="'+esc(s.events_url)+'">'+evLines.length+' URL'+(evLines.length>1?'s':'')+'</small>' : '<small style="color:#aaa">–</small>';
    var statusCls = s.last_error ? 'sbe-status--scraped' : 'sbe-status--published';
    var statusLbl = s.last_error ? 'Fehler' : 'OK';
    return '<tr id="sbe-src-row-'+s.id+'" data-id="'+s.id+'">'
      +'<td class="sbe-col-name"><strong>'+esc(s.name)+'</strong></td>'
      +'<td><small>'+esc(s.base_url)+'</small></td>'
      +'<td style="text-align:center">'+evUrl+'</td>'
      +'<td><span class="sbe-status '+cls+'">'+lbl+'</span></td>'
      +'<td>'+scraped+' '+err+'</td>'
      +'<td style="text-align:center">'+(parseInt(s.events_found)||0)+'</td>'
      +'<td><span class="sbe-status '+statusCls+'">'+statusLbl+'</span></td>'
      +'<td class="sbe-col-actions">'
        +'<button class="button button-small sbe-btn-edit-source" data-src="'+j+'">Bearbeiten</button>'
        +'<button class="button button-small sbe-btn-delete-source" data-id="'+s.id+'" style="color:#d32f2f;border-color:#ef9a9a">✕</button>'
      +'</td></tr>';
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // SETTINGS
  // ══════════════════════════════════════════════════════════════════════════════

  $('#sbe-btn-save-settings').on('click', function(){
    var $btn = $(this).prop('disabled', true);
    $.post(SBE.ajax_url, {
      action: 'sbe_save_settings', nonce: SBE.nonce,
      user_agent:       $('#sbe-set-useragent').val(),
      request_timeout:  $('#sbe-set-timeout').val(),
      request_delay:    $('#sbe-set-delay').val(),
      max_events:       $('#sbe-set-maxevents').val(),
      retry_count:      $('#sbe-set-retries').val(),
      retry_delay:      $('#sbe-set-retrydelay').val(),
      accept_language:  $('#sbe-set-lang').val(),
      follow_redirects: $('#sbe-set-redirects').is(':checked') ? 1 : 0,
      check_sitemap:    $('#sbe-set-sitemap').is(':checked') ? 1 : 0,
      dedup_window:       $('#sbe-set-dedup').val(),
      blacklist_patterns: $('#sbe-set-blacklist').val(),
      crawl_depth:        $('#sbe-set-depth').val(),
    }, function(res){
      $btn.prop('disabled', false);
      if (res.success) sbeShowNotice(res.data.message, 'success');
      else sbeShowNotice('Fehler beim Speichern.', 'error');
    });
  });

  // ══════════════════════════════════════════════════════════════════════════════
  // EXTERNAL EVENTS (Eventim / Eventbrite / Reservix)
  // ══════════════════════════════════════════════════════════════════════════════

  var extEvents = []; // Store search results for import

  $('#sbe-btn-ext-search').on('click', function(){
    var query = $('#sbe-ext-query').val().trim();
    if (!query) { alert('Bitte einen Suchbegriff eingeben.'); return; }
    var $btn = $(this).prop('disabled', true);
    $('#sbe-ext-progress').show().find('#sbe-ext-progress-label').text('Durchsuche Eventim, Eventbrite, Reservix…');
    $('#sbe-ext-table, #sbe-ext-empty, #sbe-ext-batch, #sbe-ext-log').hide();
    $('#sbe-ext-tbody').html('');
    extEvents = [];

    $.ajax({
      url: SBE.ajax_url, type: 'POST', timeout: 60000,
      data: {action:'sbe_search_external', nonce:SBE.nonce, query:query},
      success: function(res){
        $btn.prop('disabled', false);
        $('#sbe-ext-progress').hide();
        if (!res.success) { alert('Fehler: ' + (res.data || 'Unbekannt')); return; }
        var d = res.data;
        extEvents = d.events || [];

        // Show log
        if (d.log && d.log.length) {
          var logHtml = d.log.map(function(l){
            var cls = l.charAt(0)==='✓' ? 'sbe-ad-ok' : 'sbe-ad-info';
            return '<span class="'+cls+'">'+esc(l)+'</span>';
          }).join(' · ');
          $('#sbe-ext-log').html(logHtml).show();
        }

        if (extEvents.length === 0) {
          $('#sbe-ext-empty').show();
          return;
        }

        // Render results
        var rows = '';
        extEvents.forEach(function(ev, idx){
          var date = ev.start_date ? ev.start_date.substring(8,10)+'.'+ev.start_date.substring(5,7)+'.'+ev.start_date.substring(0,4) : '–';
          rows += '<tr data-ext-idx="'+idx+'">'
            +'<td><input type="checkbox" class="sbe-ext-check" value="'+idx+'"/></td>'
            +'<td><strong>'+esc(ev.name)+'</strong>'+(ev.url ? '<br><a href="'+esc(ev.url)+'" target="_blank" style="font-size:10px;color:#0073aa">↗ öffnen</a>' : '')+'</td>'
            +'<td>'+date+'</td>'
            +'<td>'+esc(ev.location||'')+'</td>'
            +'<td>'+(ev.price ? esc(ev.price)+' €' : '–')+'</td>'
            +'<td><span class="sbe-ext-source">'+esc(ev.source||'')+'</span></td>'
            +'<td>'+esc(ev.event_type||'')+'</td>'
            +'<td><button class="button button-small sbe-btn-ext-import" data-idx="'+idx+'">Importieren</button></td>'
            +'</tr>';
        });
        $('#sbe-ext-tbody').html(rows);
        $('#sbe-ext-table').show();
        sbeShowNotice(extEvents.length + ' externe Events gefunden.', 'success');
      },
      error: function(xhr, status){
        $btn.prop('disabled', false);
        $('#sbe-ext-progress').hide();
        alert(status === 'timeout' ? 'Timeout – Portale antworten zu langsam.' : 'Netzwerkfehler.');
      }
    });
  });

  // Single import
  $(document).on('click', '.sbe-btn-ext-import', function(){
    var idx = parseInt($(this).data('idx'));
    var ev = extEvents[idx];
    if (!ev) return;
    sbeImportExternalEvent(ev, $(this));
  });

  // Batch checkboxes
  $('#sbe-ext-check-all').on('change', function(){
    $('.sbe-ext-check').prop('checked', this.checked);
    sbeUpdateExtBatch();
  });
  $(document).on('change', '.sbe-ext-check', sbeUpdateExtBatch);
  function sbeUpdateExtBatch(){
    var n = $('.sbe-ext-check:checked').length;
    $('#sbe-ext-batch').toggle(n > 0);
    $('#sbe-ext-batch-count').text(n + ' ausgewählt');
  }

  // Batch import
  $('#sbe-ext-import-selected').on('click', function(){
    var indices = $('.sbe-ext-check:checked').map(function(){ return parseInt($(this).val()); }).get();
    if (!indices.length) return;
    var $btn = $(this).prop('disabled', true).text('Importiere…');
    var done = 0;
    indices.forEach(function(idx){
      var ev = extEvents[idx];
      if (!ev) { done++; return; }
      sbeImportExternalEvent(ev, null, function(){
        done++;
        if (done >= indices.length) {
          $btn.prop('disabled', false).text('Ausgewählte importieren');
          sbeShowNotice(done + ' Events importiert.', 'success');
          sbeReloadEvents();
          sbeUpdateTabCounts();
        }
      });
    });
  });

  function sbeImportExternalEvent(ev, $btn, callback) {
    if ($btn) $btn.prop('disabled', true).text('…');
    $.post(SBE.ajax_url, {
      action: 'sbe_save_event', nonce: SBE.nonce,
      name:       ev.name || '',
      start_date: (ev.start_date || '').replace('T', ' '),
      end_date:   (ev.end_date || '').replace('T', ' '),
      description: ev.description || '',
      location:   ev.location || '',
      city:       '',
      address:    '',
      organizer:  ev.source || '',
      event_type: ev.event_type || 'Sonstige',
      url:        ev.url || '',
      image_url:  ev.image_url || '',
      ticket_url: ev.ticket_url || ev.url || '',
      price:      ev.price || '',
      price_currency: ev.price_currency || 'EUR',
      status:     'scraped',
      source:     ev.source || 'extern',
    }, function(res){
      if ($btn) {
        if (res.success) {
          $btn.text('✓').css('color','#2e7d32');
          sbeReloadEvents();
          sbeUpdateTabCounts();
        } else {
          $btn.prop('disabled',false).text('Importieren');
        }
      }
      if (callback) callback();
    });
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // AI ASSISTENT
  // ══════════════════════════════════════════════════════════════════════════════

  // Export CSV
  $('#sbe-ai-export-btn').on('click', function(){
    var $btn = $(this).prop('disabled', true);
    $.post(SBE.ajax_url, {
      action: 'sbe_ai_export', nonce: SBE.nonce,
      threshold: $('#sbe-ai-threshold').val(),
      status: $('#sbe-ai-status').val()
    }, function(res){
      $btn.prop('disabled', false);
      if (!res.success) { alert('Fehler: ' + (res.data || '')); return; }
      var d = res.data;
      if (!d.csv || d.count === 0) {
        $('#sbe-ai-export-info').html('<p style="color:#2e7d32">✓ Alle Events sind vollständig – kein Export nötig.</p>');
        return;
      }
      // Download CSV
      var blob = new Blob(['\uFEFF' + d.csv], {type: 'text/csv;charset=utf-8;'});
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'spielbank-events-ai-export.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      $('#sbe-ai-export-info').html('<p style="color:#2e7d32">✓ ' + esc(d.message) + '</p>');
      sbeShowNotice(d.message, 'success');
    });
  });

  // Copy AI Prompt
  $('#sbe-ai-copy-prompt').on('click', function(){
    var prompt = 'Ich habe eine CSV-Datei mit Event-Daten von deutschen Spielbanken. Viele Felder sind leer.\n\n'
      + 'Bitte fülle die leeren Felder aus, indem du die Events online recherchierst. Wichtige Felder:\n'
      + '- description: Kurze Beschreibung des Events (2-3 Sätze, Deutsch)\n'
      + '- start_date: Format YYYY-MM-DD HH:MM:SS\n'
      + '- end_date: Format YYYY-MM-DD HH:MM:SS (wenn bekannt)\n'
      + '- image_url: URL zu einem Event-Bild\n'
      + '- ticket_url: URL zur Ticket-Buchung\n'
      + '- price: Eintrittspreis oder Buy-in (nur Zahl)\n'
      + '- event_type: Poker, Blackjack, Roulette, Slots, Musik oder Sonstige\n'
      + '- city: Stadt\n'
      + '- address: Vollständige Adresse\n\n'
      + 'Gib die vollständige CSV zurück mit denselben Spalten und Semikolon als Trennzeichen. '
      + 'Ändere keine vorhandenen Werte – fülle nur leere Felder aus. '
      + 'Die Spalte "id" darf NICHT verändert werden.\n\n'
      + 'Hinweise: Die Spalten "schema_pct" und "missing_fields" sind nur zur Info – nicht zurückgeben.';

    if (navigator.clipboard) {
      navigator.clipboard.writeText(prompt).then(function(){
        sbeShowNotice('AI-Prompt in die Zwischenablage kopiert!', 'success');
      });
    } else {
      // Fallback
      var $ta = $('<textarea>').val(prompt).appendTo('body').select();
      document.execCommand('copy');
      $ta.remove();
      sbeShowNotice('AI-Prompt in die Zwischenablage kopiert!', 'success');
    }
  });

  // Import CSV – file select
  $('#sbe-ai-import-file').on('change', function(){
    var file = this.files[0];
    $('#sbe-ai-import-btn').prop('disabled', !file);
    $('#sbe-ai-import-preview, #sbe-ai-import-result').hide();
    if (!file) return;

    var reader = new FileReader();
    reader.onload = function(e){
      var text = e.target.result;
      var lines = text.split('\n').filter(function(l){ return l.trim(); });
      var preview = '<strong>' + file.name + '</strong> – ' + (lines.length - 1) + ' Zeilen erkannt';
      // Show first 3 data lines
      if (lines.length > 1) {
        var header = lines[0].split(';').map(function(h){ return h.replace(/"/g,'').trim(); });
        preview += '<br><small style="color:#888">Spalten: ' + esc(header.join(', ')) + '</small>';
      }
      $('#sbe-ai-import-preview').html(preview).show();
    };
    reader.readAsText(file, 'UTF-8');
  });

  // Import CSV – execute
  $('#sbe-ai-import-btn').on('click', function(){
    var file = $('#sbe-ai-import-file')[0].files[0];
    if (!file) return;
    var $btn = $(this).prop('disabled', true).text('Importiere…');

    var reader = new FileReader();
    reader.onload = function(e){
      var csvData = e.target.result;
      $.post(SBE.ajax_url, {
        action: 'sbe_ai_import', nonce: SBE.nonce,
        csv_data: csvData
      }, function(res){
        $btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> CSV importieren');
        if (!res.success) {
          $('#sbe-ai-import-result').html('<p style="color:#d32f2f">✗ ' + esc(res.data) + '</p>').show();
          return;
        }
        var d = res.data;
        var html = '<p style="color:#2e7d32"><strong>✓ ' + esc(d.message) + '</strong></p>';
        if (d.log && d.log.length) {
          html += '<ul style="font-size:12px;color:#555;margin-top:6px">';
          d.log.forEach(function(l){ html += '<li>' + esc(l) + '</li>'; });
          html += '</ul>';
        }
        $('#sbe-ai-import-result').html(html).show();
        sbeShowNotice(d.message, 'success');
        sbeReloadEvents();
        sbeUpdateTabCounts();
      });
    };
    reader.readAsText(file, 'UTF-8');
  });

  // ══════════════════════════════════════════════════════════════════════════════
  // EVENTS (unchanged logic)
  // ══════════════════════════════════════════════════════════════════════════════

  $('#sbe-btn-approve-all').on('click', function(){
    if (!confirm('Alle gescrapten Events genehmigen?')) return;
    var ids = [];
    $('#sbe-tbody tr').each(function(){
      if ($(this).find('.sbe-status--scraped').length) ids.push($(this).data('id'));
    });
    Promise.all(ids.map(function(id){ return sbeSetEventStatus(id,'approved'); }))
      .then(function(){ sbeShowNotice('Alle Events genehmigt.','success'); sbeReloadEvents(); });
  });

  $('#sbe-btn-publish').on('click', function(){
    if (!confirm('Alle genehmigten Events veröffentlichen?')) return;
    $(this).prop('disabled',true);
    var self = this;
    $.post(SBE.ajax_url, {action:'sbe_publish', nonce:SBE.nonce}, function(res){
      $(self).prop('disabled',false);
      if (res.success) {
        sbeShowNotice(res.data.message + ' — [spielbank_events] Shortcode ist aktualisiert.','success');
        sbeReloadEvents();
      }
    });
  });

  $('#sbe-btn-add-event').on('click', function(){ sbeOpenEventModal({}); });

  $(document).on('click', '.sbe-btn-edit-event', function(){
    var ev = $(this).data('ev');
    sbeOpenEventModal(typeof ev==='string' ? JSON.parse(ev) : ev);
  });

  $(document).on('click', '.sbe-btn-approve-row', function(){
    sbeSetEventStatus($(this).data('id'), 'approved')
      .then(function(){ sbeReloadEvents(); sbeShowNotice('Event genehmigt.','success'); });
  });

  $(document).on('click', '.sbe-btn-delete-event', function(){
    if (!confirm('Event löschen?')) return;
    $.post(SBE.ajax_url, {action:'sbe_delete_event', nonce:SBE.nonce, id:$(this).data('id')}, function(res){
      if (res.success) { sbeReloadEvents(); sbeShowNotice('Event gelöscht.','success'); sbeUpdateTabCounts(); }
    });
  });

  // ══════════════════════════════════════════════════════════════════════════════
  // LOCATIONS (unchanged logic)
  // ══════════════════════════════════════════════════════════════════════════════

  $('#sbe-btn-add-location').on('click', function(){ sbeOpenLocationModal({}); });

  $(document).on('click', '.sbe-btn-edit-location', function(){
    var loc = $(this).data('loc');
    sbeOpenLocationModal(typeof loc==='string' ? JSON.parse(loc) : loc);
  });

  $(document).on('click', '.sbe-btn-delete-location', function(){
    if (!confirm('Standort und alle Öffnungszeiten löschen?')) return;
    $.post(SBE.ajax_url, {action:'sbe_delete_location', nonce:SBE.nonce, id:$(this).data('id')}, function(res){
      if (res.success) { sbeReloadLocations(); sbeShowNotice('Standort gelöscht.','success'); }
    });
  });

  // ── Checkboxes / Batch ────────────────────────────────────────────────────
  $('#sbe-check-all').on('change', function(){
    $('.sbe-row-check').prop('checked', this.checked);
    sbeUpdateBatch();
  });
  $(document).on('change', '.sbe-row-check', sbeUpdateBatch);
  function sbeUpdateBatch(){
    var n = $('.sbe-row-check:checked').length;
    $('#sbe-batch-bar').toggle(n > 0);
    $('#sbe-batch-count').text(n + ' ausgewählt');
  }
  $('#sbe-batch-approve').on('click', function(){
    Promise.all(sbeChecked().map(function(id){ return sbeSetEventStatus(id,'approved'); }))
      .then(function(){ sbeReloadEvents(); sbeShowNotice('Genehmigt.','success'); });
  });
  $('#sbe-batch-publish').on('click', function(){
    Promise.all(sbeChecked().map(function(id){ return sbeSetEventStatus(id,'published'); }))
      .then(function(){ sbeReloadEvents(); sbeShowNotice('Veröffentlicht.','success'); });
  });
  $('#sbe-batch-delete').on('click', function(){
    if (!confirm(sbeChecked().length + ' Events löschen?')) return;
    Promise.all(sbeChecked().map(function(id){
      return $.post(SBE.ajax_url,{action:'sbe_delete_event',nonce:SBE.nonce,id:id});
    })).then(function(){ sbeReloadEvents(); sbeShowNotice('Gelöscht.','success'); sbeUpdateTabCounts(); });
  });
  function sbeChecked(){ return $('.sbe-row-check:checked').map(function(){ return $(this).val(); }).get(); }

  // ── Filters ───────────────────────────────────────────────────────────────
  function sbeApplyFilters(){
    var st  = $('#sbe-filter-status').val().toLowerCase();
    var ty  = $('#sbe-filter-type').val().toLowerCase();
    var q   = $('#sbe-filter-search').val().toLowerCase();
    $('#sbe-tbody tr').each(function(){
      var $r   = $(this);
      var text = $r.text().toLowerCase();
      var stat = $r.find('.sbe-status').text().toLowerCase();
      var type = $r.find('td:nth-child(5)').text().toLowerCase();
      $r.toggle((!st||stat.includes(st)) && (!ty||type.includes(ty)) && (!q||text.includes(q)));
    });
  }
  $('#sbe-filter-status,#sbe-filter-type').on('change', sbeApplyFilters);
  $('#sbe-filter-search').on('input', sbeApplyFilters);

  // ══════════════════════════════════════════════════════════════════════════════
  // EVENT MODAL
  // ══════════════════════════════════════════════════════════════════════════════

  window.sbeOpenEventModal = function(ev){
    $('#sbe-ef-id').val(ev.id||'');
    $('#sbe-ef-name').val(ev.name||'');
    $('#sbe-ef-start').val(ev.start_date ? ev.start_date.replace(' ','T').substring(0,16) : '');
    $('#sbe-ef-end').val(ev.end_date     ? ev.end_date.replace(' ','T').substring(0,16)   : '');
    $('#sbe-ef-desc').val(ev.description||'');
    $('#sbe-ef-location').val(ev.location||'');
    $('#sbe-ef-city').val(ev.city||'');
    $('#sbe-ef-address').val(ev.address||'');
    $('#sbe-ef-organizer').val(ev.organizer||'');
    $('#sbe-ef-type').val(ev.event_type||'Sonstige');
    $('#sbe-ef-url').val(ev.url||'');
    $('#sbe-ef-image').val(ev.image_url||'');
    $('#sbe-ef-ticket').val(ev.ticket_url||'');
    $('#sbe-ef-price').val(ev.price||'');
    $('#sbe-ef-currency').val(ev.price_currency||'EUR');
    $('#sbe-ef-status').val(ev.status||'draft');
    $('#sbe-modal-event-title').text(ev.id ? 'Event bearbeiten' : 'Neues Event');
    $('#sbe-modal-event').show();
    $('#sbe-ef-name').focus();
  };

  window.sbeSaveEventModal = function(){
    var data = {
      action:'sbe_save_event', nonce:SBE.nonce,
      id:$('#sbe-ef-id').val(),
      name:$('#sbe-ef-name').val(),
      start_date:$('#sbe-ef-start').val().replace('T',' '),
      end_date:$('#sbe-ef-end').val().replace('T',' '),
      description:$('#sbe-ef-desc').val(),
      location:$('#sbe-ef-location').val(),
      city:$('#sbe-ef-city').val(),
      address:$('#sbe-ef-address').val(),
      organizer:$('#sbe-ef-organizer').val(),
      event_type:$('#sbe-ef-type').val(),
      url:$('#sbe-ef-url').val(),
      image_url:$('#sbe-ef-image').val(),
      ticket_url:$('#sbe-ef-ticket').val(),
      price:$('#sbe-ef-price').val(),
      price_currency:$('#sbe-ef-currency').val(),
      status:$('#sbe-ef-status').val(),
    };
    if (!data.name.trim()){ alert('Name erforderlich.'); return; }
    $.post(SBE.ajax_url, data, function(res){
      if(res.success){ sbeCloseModal('event'); sbeShowNotice('Event gespeichert.','success'); sbeReloadEvents(); sbeUpdateTabCounts(); }
    });
  };

  // ══════════════════════════════════════════════════════════════════════════════
  // LOCATION MODAL
  // ══════════════════════════════════════════════════════════════════════════════

  window.sbeOpenLocationModal = function(loc){
    $('#sbe-lf-id').val(loc.id||'');
    $('#sbe-lf-name').val(loc.name||'');
    $('#sbe-lf-altname').val(loc.alternate_name||'');
    $('#sbe-lf-desc').val(loc.description||'');
    $('#sbe-lf-url').val(loc.url||'');
    $('#sbe-lf-price').val(loc.price_range||'');
    $('#sbe-lf-tel').val(loc.telephone||'');
    $('#sbe-lf-email').val(loc.email||'');
    $('#sbe-lf-street').val(loc.street_address||'');
    $('#sbe-lf-city').val(loc.address_locality||'');
    $('#sbe-lf-plz').val(loc.postal_code||'');
    $('#sbe-lf-region').val(loc.address_region||'');
    $('#sbe-lf-country').val(loc.address_country||'DE');
    $('#sbe-lf-lat').val(loc.latitude||'');
    $('#sbe-lf-lng').val(loc.longitude||'');
    $('#sbe-lf-rating').val(loc.rating_value||'');
    $('#sbe-lf-reviews').val(loc.review_count||'');
    $('#sbe-lf-image').val(loc.image_url||'');
    $('#sbe-lf-logo').val(loc.logo_url||'');
    $('#sbe-lf-payment').val(loc.payment_accepted||'');
    $('#sbe-lf-currencies').val(loc.currencies_accepted||'EUR');
    $('#sbe-lf-accessible').val(loc.is_accessible||0);
    $('#sbe-lf-smoking').val(loc.smoking_allowed||0);
    $('#sbe-lf-status').val(loc.status||'active');
    $('#sbe-lf-map').val(loc.has_map||'');

    var sa = '';
    if (loc.same_as) {
      try { sa = JSON.parse(loc.same_as).join(', '); } catch(e){ sa = loc.same_as; }
    }
    $('#sbe-lf-sameas').val(sa);

    var locId = parseInt(loc.id)||0;
    sbeRenderHoursEditor(loc.hours || []);
    if (locId > 0) {
      $.post(SBE.ajax_url, {action:'sbe_get_hours', nonce:SBE.nonce, location_id:locId}, function(r){
        if (r.success) sbeRenderHoursEditor(r.data);
      });
    }

    $('#sbe-modal-loc-title').text(loc.id ? 'Standort bearbeiten' : 'Neuer Standort');
    $('#sbe-modal-location').show();
    $('#sbe-lf-name').focus();
  };

  function sbeRenderHoursEditor(rows){
    var byDay = {};
    (rows||[]).forEach(function(r){ byDay[r.day_of_week] = r; });
    var html = '<table class="sbe-hours-table"><thead><tr>'
      +'<th>Tag</th>'
      +'<th>Öffnet</th>'
      +'<th>Schließt</th>'
      +'<th>Sonderbezeichnung</th>'
      +'</tr></thead><tbody>';
    DAYS.forEach(function(day){
      var r = byDay[day] || {opens:'12:00',closes:'03:00',label:''};
      var opens  = r.opens  ? r.opens.substring(0,5)  : '12:00';
      var closes = r.closes ? r.closes.substring(0,5) : '03:00';
      html += '<tr data-day="'+day+'">'
        +'<td class="sbe-day-label">'+DAYS_DE[day]+'</td>'
        +'<td><input type="time" class="sbe-h-opens"  value="'+opens+'"/></td>'
        +'<td><input type="time" class="sbe-h-closes" value="'+closes+'"/></td>'
        +'<td><input type="text" class="sbe-h-label"  value="'+(r.label||'')+'" placeholder="z.B. Silvester"/></td>'
        +'</tr>';
    });
    html += '</tbody></table>';
    $('#sbe-hours-editor').html(html);
  }

  window.sbeSaveLocationModal = function(){
    var saRaw = $('#sbe-lf-sameas').val();
    var saArr = saRaw ? saRaw.split(',').map(function(s){ return s.trim(); }).filter(Boolean) : [];

    var data = {
      action:'sbe_save_location', nonce:SBE.nonce,
      id:$('#sbe-lf-id').val(),
      name:$('#sbe-lf-name').val(),
      alternate_name:$('#sbe-lf-altname').val(),
      description:$('#sbe-lf-desc').val(),
      url:$('#sbe-lf-url').val(),
      telephone:$('#sbe-lf-tel').val(),
      email:$('#sbe-lf-email').val(),
      street_address:$('#sbe-lf-street').val(),
      address_locality:$('#sbe-lf-city').val(),
      postal_code:$('#sbe-lf-plz').val(),
      address_region:$('#sbe-lf-region').val(),
      address_country:$('#sbe-lf-country').val(),
      latitude:$('#sbe-lf-lat').val(),
      longitude:$('#sbe-lf-lng').val(),
      price_range:$('#sbe-lf-price').val(),
      rating_value:$('#sbe-lf-rating').val(),
      review_count:$('#sbe-lf-reviews').val(),
      image_url:$('#sbe-lf-image').val(),
      logo_url:$('#sbe-lf-logo').val(),
      payment_accepted:$('#sbe-lf-payment').val(),
      currencies_accepted:$('#sbe-lf-currencies').val(),
      is_accessible:$('#sbe-lf-accessible').val(),
      smoking_allowed:$('#sbe-lf-smoking').val(),
      status:$('#sbe-lf-status').val(),
      has_map:$('#sbe-lf-map').val(),
      same_as: JSON.stringify(saArr),
    };
    if (!data.name.trim()){ alert('Name erforderlich.'); return; }

    $.post(SBE.ajax_url, data, function(res){
      if (!res.success){ sbeShowNotice('Fehler beim Speichern.','error'); return; }
      var locId = res.data.id;

      var hours = [];
      $('#sbe-hours-editor tbody tr').each(function(){
        hours.push({
          day_of_week: $(this).data('day'),
          opens:  $(this).find('.sbe-h-opens').val(),
          closes: $(this).find('.sbe-h-closes').val(),
          label:  $(this).find('.sbe-h-label').val() || null,
        });
      });
      $.post(SBE.ajax_url, {action:'sbe_save_hours', nonce:SBE.nonce, location_id:locId, hours:JSON.stringify(hours)}, function(){
        sbeCloseModal('location');
        sbeShowNotice('Standort + Öffnungszeiten gespeichert (ID: '+locId+').','success');
        sbeReloadLocations();
      });
    });
  };

  // ── Modal helpers ────────────────────────────────────────────────────────────
  window.sbeCloseModal = function(type){
    $('#sbe-modal-' + type).hide();
  };
  $('#sbe-modal-event, #sbe-modal-location, #sbe-modal-source').on('click', function(e){
    if ($(e.target).is('.sbe-modal')) sbeCloseModal($(e.target).attr('id').replace('sbe-modal-',''));
  });

  // ── Reload helpers ────────────────────────────────────────────────────────────
  function sbeReloadEvents(){
    $.post(SBE.ajax_url, {action:'sbe_get_all_events', nonce:SBE.nonce}, function(res){
      if (!res.success) return;
      $('#sbe-tbody').html(res.data.map(sbeRenderEventRow).join(''));
      sbeApplyFilters();
    });
  }

  function sbeReloadLocations(){
    $.post(SBE.ajax_url, {action:'sbe_get_all_locations', nonce:SBE.nonce}, function(res){
      if (!res.success) return;
      $('#sbe-loc-tbody').html(res.data.map(sbeRenderLocationRow).join(''));
    });
  }

  function sbeSetEventStatus(id, status){
    return $.post(SBE.ajax_url, {action:'sbe_save_event', nonce:SBE.nonce, id:id, status:status,
      name:$('#sbe-row-'+id+' .sbe-col-name strong').text()||'?'});
  }

  // ── Row render helpers (JS) ──────────────────────────────────────────────────
  function sbeRenderEventRow(ev){
    var sMap={scraped:'Gescrapt',approved:'Genehmigt',published:'Veröffentlicht',draft:'Entwurf'};
    var cMap={scraped:'sbe-status--scraped',approved:'sbe-status--approved',published:'sbe-status--published',draft:'sbe-status--draft'};
    var date = ev.start_date ? ev.start_date.substr(8,2)+'.'+ev.start_date.substr(5,2)+'.'+ev.start_date.substr(0,4)+' '+ev.start_date.substr(11,5) : '–';
    var j = JSON.stringify(ev).replace(/"/g,'&quot;');
    var schema = sbeCalcSchema(ev);
    var sClr = schema.pct >= 80 ? '#2e7d32' : (schema.pct >= 50 ? '#d46b00' : '#d32f2f');
    return '<tr id="sbe-row-'+ev.id+'" data-id="'+ev.id+'">'
      +'<td><input type="checkbox" class="sbe-row-check" value="'+ev.id+'"/></td>'
      +'<td class="sbe-col-name"><strong>'+esc(ev.name)+'</strong></td>'
      +'<td>'+date+'</td><td>'+esc(ev.city||'')+'</td><td>'+esc(ev.event_type||'')+'</td>'
      +'<td class="sbe-col-source">'+esc(ev.source||'')+'</td>'
      +'<td><div class="sbe-schema-bar" title="'+esc(schema.tip)+'"><div class="sbe-schema-fill" style="width:'+schema.pct+'%;background:'+sClr+'"></div><span class="sbe-schema-pct">'+schema.pct+'%</span></div></td>'
      +'<td><span class="sbe-status '+(cMap[ev.status]||'')+'">'+sMap[ev.status]+'</span></td>'
      +'<td class="sbe-col-actions">'
        +'<button class="button button-small sbe-btn-edit-event" data-ev="'+j+'">Bearbeiten</button>'
        +'<button class="button button-small sbe-btn-approve-row" data-id="'+ev.id+'">✓</button>'
        +'<button class="button button-small sbe-btn-delete-event" data-id="'+ev.id+'">✕</button>'
      +'</td></tr>';
  }

  function sbeCalcSchema(ev){
    var fields = [
      {key:'name',       label:'Name',          w:2, ok:!!(ev.name)},
      {key:'start_date', label:'startDate',     w:2, ok:!!(ev.start_date)},
      {key:'description',label:'description',   w:1, ok:!!(ev.description && ev.description.length>10)},
      {key:'location',   label:'location',      w:1, ok:!!(ev.location)},
      {key:'city',       label:'addressLocality',w:1, ok:!!(ev.city)},
      {key:'address',    label:'address',       w:1, ok:!!(ev.address)},
      {key:'organizer',  label:'organizer',     w:1, ok:!!(ev.organizer)},
      {key:'url',        label:'url',           w:1, ok:!!(ev.url)},
      {key:'image_url',  label:'image',         w:1, ok:!!(ev.image_url)},
      {key:'end_date',   label:'endDate',       w:1, ok:!!(ev.end_date)},
      {key:'event_type', label:'eventType',     w:1, ok:!!(ev.event_type)},
      {key:'ticket_url', label:'offers.url',    w:1, ok:!!(ev.ticket_url)},
      {key:'price',      label:'offers.price',  w:1, ok:!!(ev.price)},
    ];
    var tw=0,fw=0,present=[],missing=[];
    fields.forEach(function(f){
      tw+=f.w;
      if(f.ok){fw+=f.w;present.push(f.label);}else{missing.push(f.label);}
    });
    var pct = tw>0 ? Math.round(fw/tw*100) : 0;
    var tip = 'Schema: '+pct+'%\n✓ '+present.join(', ');
    if(missing.length) tip += '\n✗ '+missing.join(', ');
    return {pct:pct,tip:tip};
  }

  function sbeRenderLocationRow(loc){
    var hours = loc.hours||[];
    var j = JSON.stringify(loc).replace(/"/g,'&quot;');
    var cls = loc.status==='active' ? 'sbe-status--published' : 'sbe-status--draft';
    return '<tr id="sbe-loc-row-'+loc.id+'">'
      +'<td class="sbe-col-name"><strong>'+esc(loc.name)+'</strong><br><small>'+esc(loc.url||'')+'</small></td>'
      +'<td>'+esc(loc.address_locality||'')+'</td>'
      +'<td>'+esc(loc.postal_code||'')+'</td>'
      +'<td>'+esc(loc.telephone||'')+'</td>'
      +'<td>'+(loc.rating_value ? '★ '+loc.rating_value : '–')+'</td>'
      +'<td>'+esc(loc.price_range||'–')+'</td>'
      +'<td><span class="sbe-hours-count">'+hours.length+' Tage</span></td>'
      +'<td><span class="sbe-status '+cls+'">'+(loc.status==='active'?'Aktiv':'Entwurf')+'</span></td>'
      +'<td class="sbe-col-actions">'
        +'<button class="button button-small sbe-btn-edit-location" data-loc="'+j+'">Bearbeiten</button>'
        +'<button class="button button-small sbe-btn-delete-location" data-id="'+loc.id+'">✕</button>'
      +'</td></tr>';
  }

  function esc(s){ return $('<div>').text(s).html(); }

  function sbeFormatDate(d){
    if (!d) return '–';
    var p = d.split(/[\s\-:T]/);
    return p[2]+'.'+p[1]+'.'+p[0]+' '+p[3]+':'+p[4];
  }

  function sbeShowNotice(msg, type){
    $('#sbe-notice').removeClass('sbe-notice--success sbe-notice--error')
      .addClass('sbe-notice--'+(type||'success')).text(msg).show();
    setTimeout(function(){ $('#sbe-notice').fadeOut(); }, 5000);
  }
});

/* ══════════════════════════════════════════════════════════════════════════════
   WIZARD: Quick-Add Source (only needs a base URL)
   ══════════════════════════════════════════════════════════════════════════════ */
jQuery(function($){
  var wizardState = {};

  // Override the "Add" button to show wizard
  $('#sbe-btn-add-source').off('click').on('click', function(){
    sbeOpenSourceWizard();
  });

  function sbeOpenSourceWizard(){
    wizardState = {};
    // Hide advanced, show wizard step 1
    $('#sbe-source-advanced').hide();
    $('#sbe-wizard-step1').show();
    $('#sbe-wizard-step2').hide();
    $('#sbe-wizard-url').val('');
    $('#sbe-modal-source-title').text('Neue Quelle hinzufügen');
    $('#sbe-modal-source').show();
    setTimeout(function(){ $('#sbe-wizard-url').focus(); }, 100);
  }

  // When editing existing source, go straight to advanced mode
  var origOpenSource = window.sbeOpenSourceModal;
  window.sbeOpenSourceModal = function(s){
    if (s && s.id) {
      // Edit mode → show advanced form directly
      $('#sbe-wizard-step1, #sbe-wizard-step2').hide();
      $('#sbe-source-advanced').show();
      origOpenSource(s);
    } else {
      // New source → show wizard
      sbeOpenSourceWizard();
    }
  };

  // Scan button
  $('#sbe-wizard-scan-btn').on('click', function(){
    var url = $('#sbe-wizard-url').val().trim();
    if (!url) { $('#sbe-wizard-url').focus(); return; }
    if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
    $('#sbe-wizard-url').val(url);
    startWizardScan(url);
  });

  // Enter key in URL field
  $('#sbe-wizard-url').on('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); $('#sbe-wizard-scan-btn').click(); }
  });

  // Back button
  $('#sbe-wizard-back-btn').on('click', function(){
    $('#sbe-wizard-step2').hide();
    $('#sbe-wizard-step1').show();
    $('#sbe-wizard-url').focus();
  });

  // Advanced button → fill form from wizard state and switch
  $('#sbe-wizard-advanced-btn').on('click', function(){
    fillAdvancedFromWizard();
    $('#sbe-wizard-step1, #sbe-wizard-step2').hide();
    $('#sbe-source-advanced').show();
  });

  // Save from wizard
  $('#sbe-wizard-save-btn').on('click', function(){
    fillAdvancedFromWizard();
    window.sbeSaveSourceModal();
  });

  function fillAdvancedFromWizard(){
    var s = wizardState;
    var baseUrl = s.base_url || $('#sbe-wizard-url').val();

    if (!$('#sbe-sf-id').val()) $('#sbe-sf-id').val('');
    if (!$('#sbe-sf-name').val()) $('#sbe-sf-name').val(s.name || '');
    if (!$('#sbe-sf-baseurl').val()) $('#sbe-sf-baseurl').val(baseUrl);

    // Collect checked event URLs
    var evUrls = [];
    $('#sbe-wiz-events-list .sbe-wiz-url-check:checked').each(function(){
      evUrls.push($(this).val());
    });
    if (evUrls.length > 0 && !$('#sbe-sf-eventsurl').val().trim()) {
      $('#sbe-sf-eventsurl').val(evUrls.join('\n'));
    }

    if (s.contact_url && !$('#sbe-sf-contacturl').val()) $('#sbe-sf-contacturl').val(s.contact_url);
    if (s.sitemap_url && !$('#sbe-sf-sitemapurl').val()) $('#sbe-sf-sitemapurl').val(s.sitemap_url);
    if (s.robots_url && !$('#sbe-sf-robotsurl').val()) $('#sbe-sf-robotsurl').val(s.robots_url);
    if (!$('#sbe-sf-active').val()) $('#sbe-sf-active').val('1');

    if (s.seed_data && !$('#sbe-sf-seed').val().trim()) {
      $('#sbe-sf-seed').val(JSON.stringify(s.seed_data, null, 2));
    }
  }

  function startWizardScan(baseUrl){
    wizardState = { base_url: baseUrl };

    // Switch to step 2
    $('#sbe-wizard-step1').hide();
    $('#sbe-wizard-step2').show();
    $('#sbe-wizard-found-url').text(baseUrl);
    $('#sbe-wizard-found-name').text('Scanne…');

    // Reset progress steps
    $('.sbe-wiz-pstep').removeClass('active done failed').find('.sbe-wiz-pstep-icon').text('⏳');

    // Reset results
    $('#sbe-wizard-results').hide();
    $('#sbe-wizard-scan-log').text('');
    $('#sbe-wizard-log-details').removeAttr('open');

    // Run scan step by step
    var steps = ['robots', 'sitemap', 'homepage', 'bruteforce_events', 'bruteforce_contact', 'done'];
    var stepLabels = {
      robots: 'robots', sitemap: 'sitemap', homepage: 'homepage',
      bruteforce_events: 'bruteforce', bruteforce_contact: 'bruteforce', done: 'bruteforce'
    };
    var state = {};
    var allLog = [];
    var stepIdx = 0;

    function runNextStep(){
      if (stepIdx >= steps.length) {
        finishWizardScan(state, allLog);
        return;
      }
      var step = steps[stepIdx];
      var pLabel = stepLabels[step] || step;

      // Highlight current step
      $('.sbe-wiz-pstep').removeClass('active');
      $('.sbe-wiz-pstep[data-step="' + pLabel + '"]').addClass('active')
        .find('.sbe-wiz-pstep-icon').text('🔄');

      $.ajax({
        url: SBE.ajax_url, type: 'POST', timeout: 60000,
        data: {
          action: 'sbe_autodiscover', nonce: SBE.nonce,
          base_url: baseUrl, step: step, state: JSON.stringify(state)
        },
        success: function(res){
          if (!res.success) {
            allLog.push('⚠ Fehler bei Schritt: ' + step);
            $('.sbe-wiz-pstep[data-step="' + pLabel + '"]').addClass('failed')
              .find('.sbe-wiz-pstep-icon').text('✗');
          } else {
            var d = res.data;
            state = d.state || state;
            if (state.log) {
              allLog = state.log;
            }

            // Mark step done
            $('.sbe-wiz-pstep[data-step="' + pLabel + '"]').removeClass('active').addClass('done')
              .find('.sbe-wiz-pstep-icon').text('✓');

            // Update name if found
            if (state.name) {
              $('#sbe-wizard-found-name').text(state.name);
            }

            // Jump to done if autodiscover says so
            if (d.next === 'done' && step !== 'done') {
              // Skip remaining steps, mark remaining as done
              stepIdx = steps.indexOf('done');
              runNextStep();
              return;
            }
          }
          stepIdx++;
          // Small delay for visual effect
          setTimeout(runNextStep, 200);
        },
        error: function(){
          allLog.push('⚠ Netzwerkfehler bei Schritt: ' + step);
          $('.sbe-wiz-pstep[data-step="' + pLabel + '"]').addClass('failed')
            .find('.sbe-wiz-pstep-icon').text('✗');
          stepIdx++;
          setTimeout(runNextStep, 200);
        }
      });
    }

    runNextStep();
  }

  function finishWizardScan(state, allLog){
    wizardState = $.extend(wizardState, state);

    // Show results
    $('#sbe-wizard-results').show();
    $('#sbe-wizard-scan-log').text(allLog.join('\n'));

    // ── Business data card ──
    var seed = state.seed_data || {};
    var seedKeys = Object.keys(seed);
    if (seedKeys.length > 0) {
      var html = '';
      var displayMap = {
        name:'Name', telephone:'Telefon', email:'E-Mail',
        street_address:'Straße', address_locality:'Stadt',
        postal_code:'PLZ', address_region:'Bundesland',
        url:'Website', price_range:'Preisklasse',
        latitude:'Breitengrad', longitude:'Längengrad',
        rating_value:'Bewertung', review_count:'Bewertungen'
      };
      seedKeys.forEach(function(k){
        var label = displayMap[k] || k;
        var val = seed[k];
        if (typeof val === 'object') val = JSON.stringify(val);
        if (val && String(val).length > 0) {
          html += '<div class="sbe-wiz-kv"><span class="sbe-wiz-kv-key">' + esc(label) + '</span><span class="sbe-wiz-kv-val">' + esc(String(val)) + '</span></div>';
        }
      });
      $('#sbe-wiz-seed-preview').html(html || '<p class="sbe-wiz-empty">Keine JSON-LD Daten gefunden.</p>');
      $('#sbe-wiz-seed-badge').text(seedKeys.length + ' Felder').removeClass('badge-none');
    } else {
      $('#sbe-wiz-seed-preview').html('<p class="sbe-wiz-empty">Keine JSON-LD Business-Daten auf der Seite gefunden. Du kannst Seed-Daten manuell in den erweiterten Einstellungen eingeben.</p>');
      $('#sbe-wiz-seed-badge').text('0').addClass('badge-none');
    }

    // ── Events URLs card ──
    var evUrls = state.events_urls || [];
    if (evUrls.length > 0) {
      var html = '';
      evUrls.forEach(function(url, i){
        var shortUrl = url.replace(/^https?:\/\/(www\.)?/, '');
        if (shortUrl.length > 60) shortUrl = shortUrl.substring(0, 57) + '…';
        var tag = '';
        if (/sitemap/i.test(url)) tag = 'Sitemap';
        else if (/event|veranstaltung/i.test(url)) tag = 'Events';
        else if (/news|aktuell/i.test(url)) tag = 'News';
        else if (/programm|kalender/i.test(url)) tag = 'Programm';
        else if (/poker|turnier/i.test(url)) tag = 'Poker';
        else tag = 'Seite';

        html += '<div class="sbe-wiz-url-item">'
          + '<input type="checkbox" class="sbe-wiz-url-check" value="' + esc(url) + '" checked id="sbe-wiz-ev-' + i + '"/>'
          + '<label for="sbe-wiz-ev-' + i + '">' + esc(shortUrl) + '</label>'
          + '<span class="sbe-wiz-url-tag">' + tag + '</span>'
          + '</div>';
      });
      html += '<div style="margin-top:8px"><button type="button" class="button button-small" id="sbe-wiz-add-custom-url">+ URL manuell hinzufügen</button></div>';
      $('#sbe-wiz-events-list').html(html);
      $('#sbe-wiz-events-badge').text(evUrls.length);
    } else {
      $('#sbe-wiz-events-list').html(
        '<p class="sbe-wiz-empty">Keine Event-Seiten automatisch gefunden.</p>'
        + '<div style="margin-top:8px"><button type="button" class="button button-small" id="sbe-wiz-add-custom-url">+ URL manuell hinzufügen</button></div>'
      );
      $('#sbe-wiz-events-badge').text('0').addClass('badge-warn');
    }

    // ── Contact card ──
    if (state.contact_url) {
      var shortContact = state.contact_url.replace(/^https?:\/\/(www\.)?/, '');
      $('#sbe-wiz-contact-preview').html('<p>✓ <a href="' + esc(state.contact_url) + '" target="_blank">' + esc(shortContact) + '</a></p>');
    } else {
      $('#sbe-wiz-contact-preview').html('<p class="sbe-wiz-empty">Keine Kontaktseite gefunden.</p>');
    }

    // ── Technical card ──
    var techHtml = '';
    if (state.robots_url) techHtml += '<p><strong>robots.txt:</strong> ' + esc(state.robots_url) + '</p>';
    if (state.sitemap_url) techHtml += '<p><strong>Sitemap:</strong> ' + esc(state.sitemap_url) + '</p>';
    $('#sbe-wiz-technical-preview').html(techHtml || '<p class="sbe-wiz-empty">–</p>');

    // Update the title
    if (state.name) {
      $('#sbe-wizard-found-name').text(state.name);
    } else {
      $('#sbe-wizard-found-name').text('Scan abgeschlossen');
    }
  }

  // Add custom URL handler (delegated)
  $(document).on('click', '#sbe-wiz-add-custom-url', function(){
    var url = prompt('Event-URL eingeben:');
    if (!url || !url.trim()) return;
    url = url.trim();
    if (!/^https?:\/\//i.test(url)) url = 'https://' + url;

    var i = $('#sbe-wiz-events-list .sbe-wiz-url-item').length;
    var shortUrl = url.replace(/^https?:\/\/(www\.)?/, '');
    var html = '<div class="sbe-wiz-url-item">'
      + '<input type="checkbox" class="sbe-wiz-url-check" value="' + esc(url) + '" checked id="sbe-wiz-ev-' + i + '"/>'
      + '<label for="sbe-wiz-ev-' + i + '">' + esc(shortUrl) + '</label>'
      + '<span class="sbe-wiz-url-tag">Manuell</span>'
      + '</div>';
    $(this).before(html);

    // Update badge
    var count = $('#sbe-wiz-events-list .sbe-wiz-url-check').length;
    $('#sbe-wiz-events-badge').text(count).removeClass('badge-warn');

    // Also add to state
    if (!wizardState.events_urls) wizardState.events_urls = [];
    wizardState.events_urls.push(url);
  });

  function esc(s){
    return $('<div>').text(s||'').html();
  }
});
