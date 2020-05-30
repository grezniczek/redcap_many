// @ts-check
// 
// Multiple EM
//
;(function(){

//#region Globals & Data Transfer ----------------------------------------------------------

// Setup data transfer object.
// @ts-ignore
var EM = window.ExternalModules
if (typeof EM == 'undefined') {
    EM = {}
    // @ts-ignore
    window.ExternalModules = EM
}
/** @type {MultipleDTO} DTO */
var DTO = EM.MultipleEM_DTO || {}
EM.MultipleEM_DTO = DTO

/** @type {RecordLinkState} */
var rls = {
    $clear: null,
    $counter: null
}

/** @type {MultipleRecords} */
var multipleRecords = {}

/** @type {MultipleInstances} */
var multipleInstances = {}

/** @type {RecordStatusDashboardState} */
var rsdState = {
    visible: false,
    $statusBarToggle: null,
    $toggleAllCheckbox: null
}

/** @type {RecordHomePageState} */
var rhpState = {
    $addRemoveLink: null,
    record: null,
    record_selected: false,
    visible: {}
}


//#endregion


//#region Logging --------------------------------------------------------------------------

/**
 * Logs stuff to the console when in debug mode.
 */
function log() {
    if (DTO.debug && arguments.length) {
        var args = Array.from(arguments)
        args.forEach(function(arg) {
            console.log(DTO.name + ':', arg)
        })
    }
}

//#endregion


//#region -- Data Collection Menu --------------------------------------------------------

function addDataCollectionLink() {
    var $menu = $('<div></div>')
        .addClass('hang')
        .css('position', 'relative')
        .append($(getBrandIconHTML()).addClass('fs14'))
        .append('&nbsp;&nbsp;')
        .append($('<a></a>')
            .attr('href', DTO.link.href)
            .text(DTO.link.name))
        .append($('<span></span>')
            .addClass('badge badge-secondary multiple-em-menu-link-count'))
    var $ip = $('#projMenuDataCollection').parent().parent().find('div.hang').last()
    $menu.insertAfter($ip.next('.menuboxsub').length ? $ip.next() : $ip)
    $menu.find('.multiple-em-menu-link-count')
        .after($('<a href="javascript:;" class="multiple-em-menu-link-clear"></a>')
            .text(DTO.link.clearText)
            .on('click', clearAllRecords)
        )
    rls.$counter = $menu.find('.multiple-em-menu-link-count')
    rls.$clear = $menu.find('.multiple-em-menu-link-clear')
    if (DTO.rhp.init) {
        rls.$clear.after($('<a></a>')
            .addClass('multiple-em-menu-link-addremoverecord')
            .attr('href', 'javascript:;')
            .on('click', addRemoveRecord))
        .after(' &ndash; ')
        rhpState.$addRemoveLink = $menu.find('.multiple-em-menu-link-addremoverecord')
    }
    updateDataCollectionLink()
}

function updateDataCollectionLink() {
    var count = 0
    Object.keys(multipleRecords).forEach(function(key) {
        if (multipleRecords[key]) count++
    })
    rls.$counter.text(count)
    if (count) {
        rls.$clear.show()
    }
    else {
        rls.$clear.hide()
    }
    if (DTO.rhp.init) {
        if (rhpState.record_selected) {
            rhpState.$addRemoveLink.text(DTO.link.removeText).show()
            rls.$counter.addClass('badge-primary')
            rls.$counter.removeClass('badge-secondary')
        }
        else {
            rhpState.$addRemoveLink.text(DTO.link.addText).show()
            rls.$counter.addClass('badge-secondary')
            rls.$counter.removeClass('badge-primary')
        }
    }
}

//#endregion


//#region -- Record Status Dashboard -----------------------------------------------------

function toggleRecordStatusDashboardCheckBoxes() {
    var $table = $('#record_status_table')
    var $toggle = $('.multiple-em-toggle-display')
    if (rsdState.visible) {
        // Remove (hide) checkboxes
        rsdState.$statusBarToggle.removeClass('statuslink_selected').addClass('statuslink_unselected')
        $toggle.hide()
    }
    else {
        // Show checkboxes
        rsdState.$statusBarToggle.removeClass('statuslink_unselected').addClass('statuslink_selected')
        $toggle.show()
        if (!$toggle.length) {
            rsdState.$toggleAllCheckbox = $('<input type="checkbox" class="multiple-em-toggle-all"/>').on('change', toggleAll)
            // Add column.
            $table.find('thead th').first().after($('<th class="multiple-em-checkbox-col multiple-em-toggle-display"></th>')
                .attr('rowspan', $table.find('thead tr').length)
                .append($('<div></div>')
                    .addClass('multiple-em-checkbox-wrapper')
                    .append(rsdState.$toggleAllCheckbox)
                )
            )
            $table.find('tbody tr').each(function() {
                var $tr = $(this)
                var href = $tr.find('a').first().attr('href')
                var id = href.split('&id=')[1]
                if (id.includes('&')) id = id.split('&')[0]
                id = decodeURI(id)
                var $recordTD = $tr.find('td').first()
                $recordTD.after('<td class="multiple-em-checkbox-col multiple-em-toggle-display"><div class="multiple-em-checkbox-wrapper"><input data-multiple-em-record="' + id + '" type="checkbox"></div></td>')
                var $cb = $tr.find('input[data-multiple-em-record]')
                $recordTD.on('click', function(e) {
                    if (rsdState.visible && e.target.nodeName == 'TD') {
                        $cb.prop('checked', !$cb.prop('checked'))
                    }
                })
            })
            // Add toolbar.
            $('<div class="multiple-em-select-toolbar multiple-em-toggle-display"></div>')
                .append($('<a href="javascript:;"></a>')
                    .on('click', applyDashboardRecordSelection)
                    .text(DTO.rsd.apply))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', restoreDashboardRecordSelection)
                    .text(DTO.rsd.restore))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', addAllDashboardRecords)
                    .text(DTO.rsd.addAll))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', removeAllDashboardRecords)
                    .text(DTO.rsd.removeAll))
                .insertBefore($table)
            // Any checked?
            updateRecordStatusDashboardSelection()
        }
    }
    rsdState.visible = !rsdState.visible
    log('Toggled checkboxes.')
}

function setupRecordStatusDashboard() {
    var $icon = $(getBrandIconHTML())
        .addClass('fs12')
    rsdState.$statusBarToggle = $('<a></a>')
        .addClass('statuslink_unselected')
        .attr('href', 'javascript:;')
        .text(DTO.name)
        .on('click', toggleRecordStatusDashboardCheckBoxes)
    $('a.statuslink_unselected').parent().find('a').first().before(rsdState.$statusBarToggle)
    rsdState.$statusBarToggle.before($icon)
    $icon.after(' ')
    rsdState.$statusBarToggle.after('&nbsp; | &nbsp;')
    // Auto-show?
    if (DTO.rsd.activate) toggleRecordStatusDashboardCheckBoxes()
}

/**
 * Updates the checkboxes to reflect the status in the Record Selection
 */
function updateRecordStatusDashboardSelection() {
    rsdState.$toggleAllCheckbox.prop('checked', false)
    $('input[data-multiple-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-multiple-em-record')
        $cb.prop('checked', multipleRecords[id] == true)
    })
}

//#endregion


//#region -- Record Home Page ------------------------------------------------------------

/**
 * Applies changes to a repeating instrument table to the selected instances
 * @param {string} rit repeat_instrument_table-event_id-form_name
 */
function applyRHPInstances(rit) {
    var $rit = $('#' + rit)
    var count = 0
    // Update data
    /** @type {UpdateDiff} */ 
    var diff = {}
    $rit.find('input[data-multiple-em-instance]').each(function() {
        var $cb = $(this)
        if (typeof multipleInstances[rit] == 'undefined') {
            multipleInstances[rit] = {}
        }
        var checked = $cb.prop('checked')
        var instance = parseInt($cb.attr('data-multiple-em-instance'))
        multipleInstances[rit][instance] = checked
        diff[instance] = checked
        if (checked) count++
    })
    // Update server
    if (count == 0) {
        updateServerInstances('remove-all-instances', rit, null, null, null)
    }
    else {
        updateServerInstances('update-instances', rit, diff, null, null)
    }
    // Update counters
    $rit.find('.multiple-em-rit-instance-count').text(count)
    updateRHPToolbars()
}


//#region ---- User Interface Building & Handling ----------------------------------------

/**
 * Toggles display of the repeat instance table menu
 * @param {string} rit repeat_instrument_table-event_id-form_name
 */
function toggleRepeatInstrumentTableMenu(rit) {
    rhpState.visible[rit] = !rhpState.visible[rit]
    if (rhpState.visible[rit]) {
        $('#' + rit + ' .multiple-em-toggle-display').show()
    }
    else {
        $('#' + rit + ' .multiple-em-toggle-display').hide()
    }
}

/**
 * Builds the UI for a repeating instrument table
 * @param {string} rit repeat_instrument_table-event_id-form_name
 */
function buildRepeatInstrumentTableMenu(rit) {
    var parts = rit.split('-')
    var event_id = parts[1]
    var form_name = parts[2]
    var $rit = $('#' + rit)
    if ($rit.length) {
        // Add menu
        $rit.find('span.repeat_event_count_menu').after(
            $('<div class="multiple-em-rit" style="display:none;"></div>')
                .attr('data-multiple-em-event-id', event_id)
                .attr('data-multiple-em-form-name', form_name)
                .append(getBrandIconHTML())
                .append(' ')
                .append($('<a href="javascript:;" data-multiple-em-action="toggle"></a>').text(DTO.name))
                .append('<span class="badge badge-secondary multiple-em-rit-instance-count">0</span>')
                .append($('<div class="multiple-em-rit-menu multiple-em-toggle-display" style="display:none;"></div>')
                    .append($('<a href="javascript:;" data-multiple-em-action="apply"></a>').text('Apply')) // tt-fy
                    .append(' | ')
                    .append($('<a href="javascript:;" data-multiple-em-action="addAll"></a>').text('Add all')) // tt-fy
                    .append(' | ')
                    .append($('<a href="javascript:;" data-multiple-em-action="removeAll"></a>').text('Remove all')) // tt-fy
                )
            )
        // Add checkboxes
        $rit.find('th').attr('colspan', parseInt($rit.find('th').attr('colspan')) + 1)
        $rit.find('td.data').last().attr('colspan', $rit.find('th').attr('colspan'))
        $rit.find('td.labelrc').each(function() {
            var $td = $(this)
            var $tr = $td.parent()
            var instance = parseInt($td.text())
            $td.after('<td style="display:none;" class="labelrc multiple-em-checkbox-col multiple-em-toggle-display"><div class="multiple-em-checkbox-wrapper"><input type="checkbox"></div></td>')
            var $cb = $tr.find('input[type=checkbox]')
            $cb.attr('data-multiple-em-instance', instance)
               .attr('data-multiple-em-record', rhpState.record)
               .prop('checked', typeof multipleInstances[rit] != 'undefined' && multipleInstances[rit][instance] == true)
            // Clicking the TD to the left should act as clicking the checkbox
            $td.on('click', function(e) {
                if (rhpState.visible[rit] && e.target.nodeName == 'TD') {
                    $cb.prop('checked', !$cb.prop('checked'))
                }
            })
        })
        // Add extra row for select all checkbox
        var $extraTR = $('<tr class="multiple-em-toggle-display" style="display:none;"><td class="labelrc">&nbsp;</td><td class="labelrc multiple-em-checkbox-col"><div class="multiple-em-checkbox-wrapper"><input type="checkbox" class="multiple-em-toggle-all"></div></td><td class="data"></td></tr>')
        // Extra column?
        for (var i = 1; i < $rit.find('td.data').first().parent().find('td.data').length; i++) {
            $extraTR.append('<td class="data"></td>')
        }
        $rit.find('th').parent().after($extraTR)
        // Checking/unchecking the 'check all' checkbox should check/uncheck all others
        $rit.find('input.multiple-em-toggle-all').on('change', function(e) {
            var checked = $(e.target).prop('checked')
            $rit.find('input[data-multiple-em-instance]').prop('checked', checked)
        })
    }
    return $rit
}

/**
 * Builds the repeating instrument toolbar
 */
function buildRepeatInstrumentToolbar() {
    var $tb = $('<div class="multiple-em-rit multiple-em-rit-toolbar" style="display:none"></div>')
        .appendTo('#repeating_forms_table_parent_title')
        .append($('<div class="btn-toolbar" role="toolbar"></div>')
            .append($('<div class="btn-toolbar-text"></div>')
                .append(getBrandIconHTML())
                .append(' ' + DTO.name + ' ' + '<i>Instances</i> ')
                .append('<span class="multiple-em-instances-total-count badge badge-secondary font-weight-normal">0</span>')
                .append(' &ndash; ') // tt-fy
            )
            // Clear
            .append($('<button class="btn btn-link btn-xs multiple-em-toolbar-link" data-multiple-em-action="clear-instances"></button>')
                .text('Clear') // tt-fy
                .on('click', clearInstances)
            )
            .append('|')
            // Restore
            .append($('<button class="btn btn-link btn-xs multiple-em-toolbar-link" data-multiple-em-action="restore-instances"></button>')
                .text('Restore') // tt-fy
                .on('click', restoreInstances)
            )
            // View
            .append($('<div class="btn-group btn-group-xs"></div>')
                .append($('<button type="button" class="btn btn-secondary multiple-em-toolbar-button" data-multiple-em-action="view-instances"></button>')
                    .html('View') // tt-fy
                    .on('click', viewInstances)
                )
                .append('<button type="button" class="btn btn-secondary dropdown-toggle dropdown-toggle-split multiple-em-dropdown-toggle-view" data-toggle="dropdown"></button')
                .append('<div class="dropdown-menu multiple-em-dropdown-view"></div>')
            )
            // Update
            .append($('<div class="btn-group btn-group-xs"></div>')
                .append($('<button type="button" class="btn btn-secondary multiple-em-toolbar-button" data-multiple-em-action="update-instances"></button>')
                    .html('Update') // tt-fy
                    .on('click', updateInstances)
                )
                .append('<button type="button" class="btn btn-secondary dropdown-toggle dropdown-toggle-split multiple-em-dropdown-toggle-update" data-toggle="dropdown"></button')
                .append('<div class="dropdown-menu multiple-em-dropdown-update"></div>')
            )
        )
    if (DTO.userRights.lock_record) {
        // Lock and Unlock
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-secondary multiple-em-toolbar-button" data-multiple-em-action="lock-record-instances"></button>')
            .text('Lock') // tt-fy
            .on('click', lockUnlockInstances)
        )
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-secondary multiple-em-toolbar-button" data-multiple-em-action="unlock-record-instances"></button>')
            .text('Unlock') // tt-fy
            .on('click', lockUnlockInstances)
        )
    }
    if (DTO.userRights.record_delete) {
        // Delete
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-danger multiple-em-toolbar-button" data-multiple-em-action="delete-instances"></button>')
            .text('Delete') // tt-fy
            .on('click', showDeleteInstances)
        )
    }
    // Add view and update presets
    var $viewPresets = $tb.find('.multiple-em-dropdown-view')
    DTO.rhp.viewPresets.forEach(function(preset) {
        $viewPresets.append($('<a class="dropdown-item" href="javascript:;"></a>')
        .text(preset.name)
        .attr('data-multiple-em-preset-id', preset.id)
        .on('click', viewInstances)
        )
    })
    var $updatePresets = $tb.find('.multiple-em-dropdown-update')
    DTO.rhp.updatePresets.forEach(function(preset) {
        $updatePresets.append($('<a class="dropdown-item" href="javascript:;"></a>')
            .text(preset.name)
            .attr('data-multiple-em-preset-id', preset.id)
            .on('click', updateInstances)
        )
    })
}

/**
 * Builds the instrument toolbar
 */
function buildInstrumentToolbar() {
    var $tb = $('<div class="multiple-em-egt multiple-em-egt-toolbar" style="display:none"></div>')
        .before('#event_grid_table')
        .append($('<div class="btn-toolbar" role="toolbar"></div>')
            .append($('<div class="btn-toolbar-text"></div>')
                .append(getBrandIconHTML())
                .append(' ' + DTO.name + ' ' + '<i>Forms</i> ')
                .append('<span class="multiple-em-forms-total-count badge badge-secondary font-weight-normal">0</span>')
                .append(' &ndash; ') // tt-fy
            )
            // Clear
            .append($('<button class="btn btn-link btn-xs multiple-em-toolbar-link" data-multiple-em-action="clear-forms"></button>')
                .text('Clear') // tt-fy
                .on('click', clearForms)
            )
            .append('|')
            // Restore
            .append($('<button class="btn btn-link btn-xs multiple-em-toolbar-link" data-multiple-em-action="restore-forms"></button>')
                .text('Restore') // tt-fy
                .on('click', restoreForms)
            )
        )
    if (DTO.userRights.lock_record) {
        // Lock and Unlock
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-secondary multiple-em-toolbar-button" data-multiple-em-action="lock-record-forms"></button>')
            .text('Lock') // tt-fy
            .on('click', lockUnlockForms)
        )
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-secondary multiple-em-toolbar-button" data-multiple-em-action="unlock-record-forms"></button>')
            .text('Unlock') // tt-fy
            .on('click', lockUnlockForms)
        )
    }
    if (DTO.userRights.record_delete) {
        // Delete
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-danger multiple-em-toolbar-button" data-multiple-em-action="delete-forms"></button>')
            .text('Delete') // tt-fy
            .on('click', showDeleteForms)
        )
    }
}

function restoreForms() {

}

/**
 * Restores checkboxes to reflect the state in Instance Selection
 */
function restoreInstances() {
    Object.keys(DTO.rhp.rit).forEach(function(rit) {
        var $rit = $('#' + rit)
        $rit.find('input.multiple-em-toggle-all').prop('checked', false)
        $rit.find('input[data-multiple-em-instance]').each(function() {
            var $cb = $(this)
            var instance = parseInt($cb.attr('data-multiple-em-instance'))
            $cb.prop('checked', typeof multipleInstances[rit] != 'undefined' && multipleInstances[rit][instance] == true)
        })
    })
}

function clearForms() {

}

/**
 * Removes all instances from the Instances Selection
 */
function clearInstances() {
    updateServerInstances('remove-all-instances', '--', null, null, null)
    multipleInstances = {}
    restoreInstances()
    updateRHPToolbars()
}

/**
 * Determines the number of total selected instances and updates the counter in the
 * repeat instrument toolbar
 */
function updateRHPToolbars() {
    var count = 0
    rhpState.record_selected = multipleRecords[rhpState.record] == true
    if (rhpState) {
        Object.keys(multipleInstances).forEach(function(rit) {
            Object.keys(multipleInstances[rit]).forEach(function(instance) {
                if (multipleInstances[rit][instance]) {
                    count++
                }
            })
        })
    }
    $('.multiple-em-instances-total-count').text(count)
    $('.multiple-em-toolbar-button').prop('disabled', count == 0)
    $('.multiple-em-dropdown-toggle-view').prop('disabled', count == 0 || !DTO.rhp.viewPresets.length)
    $('.multiple-em-dropdown-toggle-update').prop('disabled', count == 0 || !DTO.rhp.updatePresets.length)
    if (!rhpState.record_selected) {
        $('.multiple-em-toggle-display').hide()
        $('.multiple-em-rit').hide(100)
        $('.multiple-em-egt').hide(100)
        $('input[data-multiple-em-instance]').prop('checked', false)
        $('input[data-multiple-em-fomm]').prop('checked', false)
    }
}

/**
 * Adds module UI to all repeating instrument tables
 */
function setupRecordHomePage() {
    // Setup UI for each repeating instrument table
    Object.keys(DTO.rhp.rit).forEach(function(rit) {
        // Initialize Selected Instances store
        multipleInstances[rit] = {}
        DTO.rhp.rit[rit].forEach(function(instance) {
            multipleInstances[rit][instance] = true
        })
        // Set initial state to hidden
        rhpState.visible[rit] = false
        // Build HTML
        var $rit = buildRepeatInstrumentTableMenu(rit)
        // Hook up events
        $rit.find('a[data-multiple-em-action]').on('click', function() {
            var command = this.getAttribute('data-multiple-em-action')
            switch(command) {
                case 'toggle': {
                    toggleRepeatInstrumentTableMenu(rit)
                    break
                }
                case 'apply': {
                    applyRHPInstances(rit)
                    break
                }
                case 'addAll': {
                    $rit.find('input[data-multiple-em-instance]').prop('checked', true)
                    applyRHPInstances(rit)
                    break
                }
                case 'removeAll': {
                    $rit.find('input[data-multiple-em-instance]').prop('checked', false)
                    applyRHPInstances(rit)
                    break
                }
            }
        })
        // Update count
        $rit.find('.multiple-em-rit-instance-count').text(DTO.rhp.rit[rit].length)
        if (DTO.rhp.activate && rhpState.record_selected) toggleRepeatInstrumentTableMenu(rit)
    })
    // Build the toolbars
    buildInstrumentToolbar()
    buildRepeatInstrumentToolbar()
    // Is the record in the selection?
    rhpState.record_selected = multipleRecords[rhpState.record] == true
    if (rhpState.record_selected) {
        $('.multiple-em-rit').show()
        $('.multiple-em-egt').show()
        updateRHPToolbars()
    }
    // Hook up modal events
    $('.multiple-em-delete-confirmation-modal button.multiple-em-confirmed').on('click', deletionConfirmed)
    // Remove max width - TODO - this is not perfect
    $('#repeating_forms_table_parent').children('div').css('max-width', '33%').css('flex', '0 0 33%')
}

//#endregion

//#region ---- Add / Remove Record -------------------------------------------------------

/**
 * Adds or removes the current record to the Record Selection.
 * @param {any} override 
 */
function addRemoveRecord(override) {
    /** @type {UpdateDiff} */
    var diff = {}
    rhpState.$addRemoveLink.hide()
    if (typeof override != 'boolean') {
        rhpState.record_selected = !rhpState.record_selected
    }
    else {
        rhpState.record_selected = override == true
    }
    multipleRecords[rhpState.record] = rhpState.record_selected
    diff[rhpState.record] = rhpState.record_selected
    if (rhpState.record_selected) {
        $('.multiple-em-rit').show(100)
        if (DTO.rhp.activate) {
            $('.multiple-em-toggle-display').show()
        }
    }
    else {
        // Clear all instances
        Object.keys(DTO.rhp.rit).forEach(function(rit) {
            rhpState.visible[rit] = false
            applyRHPInstances(rit)
        })
    }
    updateServerSelection('update-records', diff)
    updateRHPToolbars()
}

/**
 * Determines whether the Record Home Page is shown for a new or existing 
 * record and sets some state accordingly.
 */
function determineRecordState() {
    var url = $('#record-home-link').attr('href')
    if (url.includes('&auto=')) {
        DTO.rhp.init = false
        rhpState.record = null
    }
    else {
        url.split('&').forEach(function(part) {
            if (part.startsWith('id=')) rhpState.record = part.substr(3)
        })
        rhpState.record_selected = multipleRecords[rhpState.record] == true
    }
    log('Record = ' + rhpState.record + (rhpState.record_selected ? ' (selected)' : ''))
}

//#endregion

//#region ---- Locking / Unlocking / E-Signature -----------------------------------------

function lockUnlockForms(e) {
    log('Lock/Unlock Forms - not implemented yet.')
}

/**
 * Lock or unlock the selected instances.
 * @param {JQueryEventObject} e 
 */
function lockUnlockInstances(e) {
    var $btn = $(e.target)
    var mode = $btn.attr('data-multiple-em-action')
    if (mode == 'lock-record-instances' || mode == 'unlock-record-instances') {
        log('Locking/Unlocking instances (' + mode + ').')
        // Disable toolbar
        $('.multiple-em-rit-toolbar button').prop('disabled', true)
        spinButton($btn)
        updateServerInstances(mode, '--', null, 
            function() {
                lockUnlockInstancesComplete($btn, null)
            }, 
            function(jqXHR) {
                lockUnlockInstancesComplete($btn, jqXHR)
            })
    }
}

/**
 * Unlock the selected instances.
 * @param {JQuery<Element>} $btn
 * @param {JQuery.jqXHR} jqXHR
 */
function lockUnlockInstancesComplete($btn, jqXHR) {
    unspinButton($btn, jqXHR == null)
    updateRHPToolbars()
    // Reload page
    if (jqXHR == null) setTimeout(function() { location.reload() }, 200)
}

//#endregion

//#region ---- Delete --------------------------------------------------------------------

function showDeleteForms() {
    log('Delete Forms - not implemented yet.')
}

/**
 * Asks for confirmation to delete all selected instances
 */
function showDeleteInstances() {
    // Get confirmation
    if (DTO.userRights.record_delete) {
        var $modal = $('.multiple-em-delete-confirmation-modal')
        $modal.find('.modal-title').html(DTO.rhp.deleteConfirmTitle)
        $modal.find('.modal-body').html(DTO.rhp.deleteConfirmText)
        $modal.attr('data-multiple-em-action', 'delete-instances')
        $modal.modal('show')
    }
}

/**
 * Deletes all selected instances and reloads the page
 */
function deleteInstances() {
    if (DTO.userRights.record_delete) {
        log('Deleting instances:', multipleInstances)
        // Disable buttons.
        $('.multiple-em-delete-confirmation-modal button').prop('disabled', true)
        updateServerInstances('delete-record-instances', '--', null, deletedInstances, deleteInstancesFailed)
    }
}

function deletedInstances() {
    location.reload()
}

/**
 * 
 * @param {JQuery.jqXHR} jqXHR 
 */
function deleteInstancesFailed(jqXHR) {
    var $modal = $('.multiple-em-delete-confirmation-modal button')
    // Disable buttons.
    $modal.prop('disabled', true)
    $modal.find('.modal-body').append('Failed')
    // TODO

}

//#endregion

//#region ---- View / Update Instances ---------------------------------------------------

/**
 * Show the view instances dialog
 * @param {JQueryEventObject} e 
 */
function viewInstances(e) {
    var presetId = e.target.getAttribute('data-multiple-em-preset-id')
    
    log('viewInstances with preset \'' + presetId + '\'')
}


/**
 * Show the update instances dialog
 * @param {JQueryEventObject} e 
 */
function updateInstances(e) {
    var presetId = e.target.getAttribute('data-multiple-em-preset-id')
    
    log('updateInstances with preset \'' + presetId + '\'')
}

//#endregion


//#endregion (Record Home Page)


//#region -- Modal Events -----------------------------------------------------------------

function deletionConfirmed() {
    var action = $('.multiple-em-delete-confirmation-modal').attr('data-multiple-em-action')
    if (action == 'delete-instances') {
        deleteInstances()
    }
}

//#endregion


//#region -- General UI Helpers -----------------------------------------------------------

/**
 * Gets the Multiple brand icon.
 */
function getBrandIconHTML() {
    return '<i class="far fa-check-square multiple-em-logo"></i>'
}

/**
 * Replaces a buttons content with a spinner.
 * @param {JQuery<Element>} $btn 
 */
function spinButton($btn) {
    var $temp = $('<div style="display:none;" class="multiple-em-spinning"></div>')
    $temp.html($btn.html())
    $btn.width($btn.width()) // Preserve width
    $btn.html('<div class="multiple-em-spinner"><i class="fas fa-spinner fa-pulse"></i></div>')
    $btn.append($temp)
}

/**
 * Restores a spinning button's content.
 * @param {JQuery<Element>} $btn 
 * @param {boolean|null} success 
 */
function unspinButton($btn, success) {
    $btn.find('.multiple-em-spinner').remove()
    $btn.html($btn.find('.multiple-em-spinning').html())
    $btn.addClass('multiple-em-btn-success')
    setTimeout(function() {
        $btn.removeClass('multiple-em-btn-success')
    }, 500);
}

//#endregion


//#region -- Record Selection ------------------------------------------------------------

function toggleAll() {
    $('input[data-multiple-em-record]').prop('checked', 
        $('input.multiple-em-toggle-all').prop('checked'))
}

/**
 * Adds all currently checked dashboard records to the Record Selection and
 * removes all that are not checked
 */
function applyDashboardRecordSelection() {
    /** @type {UpdateDiff} */
    var diff = {}
    $('input[data-multiple-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-multiple-em-record')
        var checked = $cb.prop('checked')
        if (typeof multipleRecords[id] == 'undefined') {
            multipleRecords[id] = checked
            diff[id] = checked
        }
        else {
            if (multipleRecords[id] != checked) {
                diff[id] = checked
            }
            multipleRecords[id] = checked
        }
    })
    updateServerSelection('update-records', diff)
    log('Updated selection')
}

/**
 * Restores the dashboard checkbox state to reflect the Record Selection
 */
function restoreDashboardRecordSelection() {
    updateRecordStatusDashboardSelection()
}

/**
 * Adds all dashboard records to the Record Selection
 */
function addAllDashboardRecords() {
    $('input[data-multiple-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-multiple-em-record')
        multipleRecords[id] = true
        $cb.prop('checked', true)
    })
    updateServerSelection('update-records', multipleRecords)
    log('Added all')
}

/**
 * Removes all dashboard records from the Record Selection
 */
function removeAllDashboardRecords() {
    $('input[data-multiple-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-multiple-em-record')
        multipleRecords[id] = false
        $cb.prop('checked', false)
    })
    updateServerSelection('remove-all-records', null)
    log('Removed all')
}

/**
 * Complete clears the Record Selection
 */ 
function clearAllRecords() {
    multipleRecords = {}
    updateServerSelection('remove-all-records', null)
}

//#endregion


//#region -- Ajax ------------------------------------------------------------------------

/**
 * Performs a server update
 * Commands are:
 *  - update-records (includes a diff)
 *  - remove-all-records
 * 
 * @param {string} cmd The command to execute
 * @param {UpdateDiff} diff
 */
function updateServerSelection(cmd, diff) {
    var data = {
        command: cmd,
        diff: diff
    }
    $.ajax({
        url: DTO.updateUrl,
        type: 'POST',
        data: 'payload=' + JSON.stringify(data),
        dataType: 'json'
    })
    .done(function(data, textStatus, jqXHR) {
        if (DTO.rsd.init) updateRecordStatusDashboardSelection()
        if (DTO.rhp.init) updateRHPToolbars()
        updateDataCollectionLink()
        log('Ajax Success', jqXHR, 'Records updated. Currently selected:', multipleRecords)
    })
    .fail(function(jqXHR, textStatus, errorThrown) {
        log('Ajax Failure:', jqXHR, textStatus, errorThrown)
    })
}

/**
 * Performs a server update
 * Commands are:
 *  - update-instances (includes a diff)
 *  - remove-all-instances
 * 
 * @param {string} cmd 
 * @param {string} rit repeat_instrument_table-event_id-form_name
 * @param {UpdateDiff} diff 
 * @param {function} callbackDone
 * @param {function} callbackFail
 */
function updateServerInstances(cmd, rit, diff, callbackDone, callbackFail) {
    var parts = rit.split('-')
    var data = {
        command: cmd,
        record: rhpState.record,
        event: parts[1],
        form: parts[2],
        diff: diff
    }
    log('Ajax Initiated:', data)
    $.ajax({
        url: DTO.updateUrl,
        type: 'POST',
        data: 'payload=' + JSON.stringify(data),
        dataType: 'json'
    })
    .done(function(data, textStatus, jqXHR) {
        log('Ajax Success:', jqXHR)
        if (callbackDone) callbackDone()
    })
    .fail(function(jqXHR, textStatus, errorThrown) {
        log('Ajax Failure: ', jqXHR, textStatus, errorThrown)
        if (callbackFail) callbackFail(jqXHR)
    })
}

//#endregion


$(function() {
    // Page has loaded - init stuff.
    log('Initializing', DTO)
    // Setup selection object.
    multipleRecords = {}
    DTO.selected.forEach(function(id) {
        multipleRecords[id] = true
    })
    // Determine state
    if (DTO.rhp.init) determineRecordState()
    addDataCollectionLink()
    if (DTO.rsd.init) setupRecordStatusDashboard()
    if (DTO.rhp.init) setupRecordHomePage()
})

})();