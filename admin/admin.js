/* Spielbank Events – Admin JS v2 */
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
  });

  // ── Scrape ──────────────────────────────────────────────────────────────────
  $('#sbe-btn-scrape').on('click', function(){
    var $btn = $(this).prop('disabled', true);
    $btn.find('.dashicons').addClass('sbe-spinning');
    $('#sbe-log').show();
    $('#sbe-log-content').text('Scraper gestartet…\n');

    $.post(SBE.ajax_url, {action:'sbe_scrape', nonce:SBE.nonce}, function(res){
      $btn.prop('disabled', false).find('.dashicons').removeClass('sbe-spinning');
      if (res.success) {
        var d = res.data;
        $('#sbe-log-content').text(d.log.join('\n') + '\n\n✓ ' + d.message);
        sbeShowNotice(d.message, 'success');
        sbeReloadEvents();
        sbeReloadLocations();
      } else {
        sbeShowNotice('Fehler beim Scrapen.', 'error');
      }
    }).fail(function(){ $btn.prop('disabled',false); sbeShowNotice('Netzwerkfehler.','error'); });
  });

  // ── Approve all ─────────────────────────────────────────────────────────────
  $('#sbe-btn-approve-all').on('click', function(){
    if (!confirm('Alle gescrapten Events genehmigen?')) return;
    var ids = [];
    $('#sbe-tbody tr').each(function(){
      if ($(this).find('.sbe-status--scraped').length) ids.push($(this).data('id'));
    });
    Promise.all(ids.map(function(id){ return sbeSetEventStatus(id,'approved'); }))
      .then(function(){ sbeShowNotice('Alle Events genehmigt.','success'); sbeReloadEvents(); });
  });

  // ── Publish ─────────────────────────────────────────────────────────────────
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

  // ── Event: Add / Edit / Delete ───────────────────────────────────────────────
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
      if (res.success) { sbeReloadEvents(); sbeShowNotice('Event gelöscht.','success'); }
    });
  });

  // ── Location: Add / Edit / Delete ────────────────────────────────────────────
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
    })).then(function(){ sbeReloadEvents(); sbeShowNotice('Gelöscht.','success'); });
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

  // ── EVENT MODAL ──────────────────────────────────────────────────────────────
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
      if(res.success){ sbeCloseModal('event'); sbeShowNotice('Event gespeichert.','success'); sbeReloadEvents(); }
    });
  };

  // ── LOCATION MODAL ──────────────────────────────────────────────────────────
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

    // sameAs: convert JSON array → comma string
    var sa = '';
    if (loc.same_as) {
      try { sa = JSON.parse(loc.same_as).join(', '); } catch(e){ sa = loc.same_as; }
    }
    $('#sbe-lf-sameas').val(sa);

    // Load hours
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
      +'<th data-sbe-tip="hours-opens">Öffnet <span class="sbe-tip-icon" data-sbe-tip="hours-opens">i</span></th>'
      +'<th data-sbe-tip="hours-closes">Schließt <span class="sbe-tip-icon" data-sbe-tip="hours-closes">i</span></th>'
      +'<th data-sbe-tip="hours-label">Sonderbezeichnung <span class="sbe-tip-icon" data-sbe-tip="hours-label">i</span></th>'
      +'</tr></thead><tbody>';
    DAYS.forEach(function(day){
      var r = byDay[day] || {opens:'12:00',closes:'03:00',label:''};
      var opens  = r.opens  ? r.opens.substring(0,5)  : '12:00';
      var closes = r.closes ? r.closes.substring(0,5) : '03:00';
      html += '<tr data-day="'+day+'">'
        +'<td class="sbe-day-label">'+DAYS_DE[day]+'</td>'
        +'<td><input type="time" class="sbe-h-opens"  value="'+opens+'"  data-sbe-tip="hours-opens"  /></td>'
        +'<td><input type="time" class="sbe-h-closes" value="'+closes+'" data-sbe-tip="hours-closes" /></td>'
        +'<td><input type="text" class="sbe-h-label"  value="'+(r.label||'')+'" placeholder="z.B. Silvester" data-sbe-tip="hours-label" /></td>'
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

      // Save hours
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
  $('#sbe-modal-event, #sbe-modal-location').on('click', function(e){
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
    return '<tr id="sbe-row-'+ev.id+'" data-id="'+ev.id+'">'
      +'<td><input type="checkbox" class="sbe-row-check" value="'+ev.id+'"/></td>'
      +'<td class="sbe-col-name"><strong>'+esc(ev.name)+'</strong></td>'
      +'<td>'+date+'</td><td>'+esc(ev.city||'')+'</td><td>'+esc(ev.event_type||'')+'</td>'
      +'<td class="sbe-col-source">'+esc(ev.source||'')+'</td>'
      +'<td><span class="sbe-status '+(cMap[ev.status]||'')+'">'+sMap[ev.status]+'</span></td>'
      +'<td class="sbe-col-actions">'
        +'<button class="button button-small sbe-btn-edit-event" data-ev="'+j+'">Bearbeiten</button>'
        +'<button class="button button-small sbe-btn-approve-row" data-id="'+ev.id+'">✓</button>'
        +'<button class="button button-small sbe-btn-delete-event" data-id="'+ev.id+'">✕</button>'
      +'</td></tr>';
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

  function sbeShowNotice(msg, type){
    $('#sbe-notice').removeClass('sbe-notice--success sbe-notice--error')
      .addClass('sbe-notice--'+(type||'success')).text(msg).show();
    setTimeout(function(){ $('#sbe-notice').fadeOut(); }, 5000);
  }
});
