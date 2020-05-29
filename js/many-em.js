// @ts-check
// 
// Many EM
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
/** @type {ManyDTO} DTO */
var DTO = EM.ManyEM_DTO || {}
EM.ManyEM_DTO = DTO

/** @type {RecordLinkState} */
var rls = {
    $clear: null,
    $counter: null
}

/** @type {ManyRecords} */
var manyRecords = {}

/** @type {ManyInstances} */
var manyInstances = {}

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
    if (DTO.debug) {
        switch(arguments.length) {
            case 1: 
            console.log(arguments[0]); 
            return;
            case 2: 
            console.log(arguments[0], arguments[1]); 
            return;
            case 3: 
            console.log(arguments[0], arguments[1], arguments[2]); 
            return;
            case 4:
                console.log(arguments[0], arguments[1], arguments[2], arguments[3]); 
                return;
            default:
                console.log(arguments);
        }
    }
}

//#endregion


function getManyIconHTML() {
    return '<i class="far fa-check-square many-em-logo"></i>'
}


//#region -- Data Collection Menu --------------------------------------------------------

function addDataCollectionLink() {
    var $menu = $('<div></div>')
        .addClass('hang')
        .css('position', 'relative')
        .append($(getManyIconHTML()).addClass('fs14'))
        .append('&nbsp;&nbsp;')
        .append($('<a></a>')
            .attr('href', DTO.link.href)
            .text(DTO.link.name))
        .append($('<span></span>')
            .addClass('badge badge-secondary many-em-menu-link-count'))
    var $ip = $('#projMenuDataCollection').parent().parent().find('div.hang').last()
    $menu.insertAfter($ip.next('.menuboxsub').length ? $ip.next() : $ip)
    $menu.find('.many-em-menu-link-count')
        .after($('<a href="javascript:;" class="many-em-menu-link-clear"></a>')
            .text(DTO.link.clearText)
            .on('click', clearAllRecords)
        )
    rls.$counter = $menu.find('.many-em-menu-link-count')
    rls.$clear = $menu.find('.many-em-menu-link-clear')
    if (DTO.rhp.init) {
        rls.$clear.after($('<a></a>')
            .addClass('many-em-menu-link-addremoverecord')
            .attr('href', 'javascript:;')
            .on('click', addRemoveRecord))
        .after(' &ndash; ')
        rhpState.$addRemoveLink = $menu.find('.many-em-menu-link-addremoverecord')
    }
    updateDataCollectionLink()
}

function updateDataCollectionLink() {
    var count = 0
    Object.keys(manyRecords).forEach(function(key) {
        if (manyRecords[key]) count++
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
    var $toggle = $('.many-em-toggle-display')
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
            rsdState.$toggleAllCheckbox = $('<input type="checkbox" class="many-em-toggle-all"/>').on('change', toggleAll)
            // Add column.
            $table.find('thead th').first().after($('<th class="many-em-checkbox-col many-em-toggle-display"></th>')
                .attr('rowspan', $table.find('thead tr').length)
                .append($('<div></div>')
                    .addClass('many-em-checkbox-wrapper')
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
                $recordTD.after('<td class="many-em-checkbox-col many-em-toggle-display"><div class="many-em-checkbox-wrapper"><input data-many-em-record="' + id + '" type="checkbox"></div></td>')
                var $cb = $tr.find('input[data-many-em-record]')
                $recordTD.on('click', function(e) {
                    if (rsdState.visible && e.target.nodeName == 'TD') {
                        $cb.prop('checked', !$cb.prop('checked'))
                    }
                })
            })
            // Add toolbar.
            $('<div class="many-em-select-toolbar many-em-toggle-display"></div>')
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
    log('Many EM - Toggled checkboxes.')
}

function setupRecordStatusDashboard() {
    var $icon = $(getManyIconHTML())
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
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        $cb.prop('checked', manyRecords[id] == true)
    })
}

//#endregion

//#region -- Record Home Page ------------------------------------------------------------

/**
 * Applies changes to a repeating instrument table to the selected instances
 * @param {string} rit repeat_instrument_table-event_id-form_name
 */
function applyRHPinstances(rit) {
    var $rit = $('#' + rit)
    var count = 0
    // Update data
    /** @type {UpdateDiff} */ 
    var diff = {}
    $rit.find('input[data-many-em-instance]').each(function() {
        var $cb = $(this)
        if (typeof manyInstances[rit] == 'undefined') {
            manyInstances[rit] = {}
        }
        var checked = $cb.prop('checked')
        var instance = parseInt($cb.attr('data-many-em-instance'))
        manyInstances[rit][instance] = checked
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
    $rit.find('.many-em-rit-instance-count').text(count)
    updateRepeatInstrumentToolbar()
}

/**
 * Toggles display of the repeat instance table menu
 * @param {string} rit repeat_instrument_table-event_id-form_name
 */
function toggleRepeatInstrumentTableMenu(rit) {
    rhpState.visible[rit] = !rhpState.visible[rit]
    if (rhpState.visible[rit]) {
        $('#' + rit + ' .many-em-toggle-display').show()
    }
    else {
        $('#' + rit + ' .many-em-toggle-display').hide()
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
            $('<div class="many-em-rit" style="display:none;"></div>')
                .attr('data-many-em-event-id', event_id)
                .attr('data-many-em-form-name', form_name)
                .append(getManyIconHTML())
                .append(' ')
                .append($('<a href="javascript:;" data-many-em-action="toggle"></a>').text(DTO.name))
                .append('<span class="badge badge-secondary many-em-rit-instance-count">0</span>')
                .append($('<div class="many-em-rit-menu many-em-toggle-display" style="display:none;"></div>')
                    .append($('<a href="javascript:;" data-many-em-action="apply"></a>').text('Apply')) // tt-fy
                    .append(' | ')
                    .append($('<a href="javascript:;" data-many-em-action="addAll"></a>').text('Add all')) // tt-fy
                    .append(' | ')
                    .append($('<a href="javascript:;" data-many-em-action="removeAll"></a>').text('Remove all')) // tt-fy
                )
            )
        // Add checkboxes
        $rit.find('th').attr('colspan', parseInt($rit.find('th').attr('colspan')) + 1)
        $rit.find('td.data').last().attr('colspan', $rit.find('th').attr('colspan'))
        $rit.find('td.labelrc').each(function() {
            var $td = $(this)
            var $tr = $td.parent()
            var instance = parseInt($td.text())
            $td.after('<td style="display:none;" class="labelrc many-em-checkbox-col many-em-toggle-display"><div class="many-em-checkbox-wrapper"><input type="checkbox"></div></td>')
            var $cb = $tr.find('input[type=checkbox]')
            $cb.attr('data-many-em-instance', instance)
               .attr('data-many-em-record', rhpState.record)
               .prop('checked', typeof manyInstances[rit] != 'undefined' && manyInstances[rit][instance] == true)
            // Clicking the TD to the left should act as clicking the checkbox
            $td.on('click', function(e) {
                if (rhpState.visible[rit] && e.target.nodeName == 'TD') {
                    $cb.prop('checked', !$cb.prop('checked'))
                }
            })
        })
        // Add extra row for select all checkbox
        var $extraTR = $('<tr class="many-em-toggle-display" style="display:none;"><td class="labelrc">&nbsp;</td><td class="labelrc many-em-checkbox-col"><div class="many-em-checkbox-wrapper"><input type="checkbox" class="many-em-toggle-all"></div></td><td class="data"></td></tr>')
        // Extra column?
        for (var i = 1; i < $rit.find('td.data').first().parent().find('td.data').length; i++) {
            $extraTR.append('<td class="data"></td>')
        }
        $rit.find('th').parent().after($extraTR)
        // Checking/unchecking the 'check all' checkbox should check/uncheck all others
        $rit.find('input.many-em-toggle-all').on('change', function(e) {
            var checked = $(e.target).prop('checked')
            $rit.find('input[data-many-em-instance]').prop('checked', checked)
        })
    }
    return $rit
}

/**
 * Builds the repeating instrument toolbar
 */
function buildRepeatInstrumentToolbar() {
    var $tb = $('<div class="many-em-rit many-em-rit-toolbar" style="display:none"></div>')
        .appendTo('#repeating_forms_table_parent_title')
        .append($('<div class="btn-toolbar" role="toolbar"></div>')
            .append($('<div class="btn-toolbar-text"></div>')
                .append(getManyIconHTML())
                .append(' ' + DTO.name + ' ' + '<i>Instances</i> ')
                .append('<span class="many-em-instances-total-count badge badge-secondary font-weight-normal">0</span>')
                .append(' &ndash; ') // tt-fy
            )
            // Clear
            .append($('<button class="btn btn-link btn-xs many-em-toolbar-link" data-many-em-action="clear-instances"></button>')
                .text('Clear') // tt-fy
                .on('click', clearInstances)
            )
            .append(' | ')
            // Restore
            .append($('<button class="btn btn-link btn-xs many-em-toolbar-link" data-many-em-action="restore-instances"></button>')
                .text('Restore') // tt-fy
                .on('click', restoreInstances)
            )
            // View
            .append($('<div class="btn-group btn-group-xs"></div>')
                .append($('<button type="button" class="btn btn-secondary many-em-toolbar-button" data-many-em-action="view-instances"></button>')
                    .html('View') // tt-fy
                    .on('click', viewInstances)
                )
                .append('<button type="button" class="btn btn-secondary dropdown-toggle dropdown-toggle-split many-em-dropdown-toggle-view" data-toggle="dropdown"></button')
                .append('<div class="dropdown-menu many-em-dropdown-view"></div>')
            )
            // Update
            .append($('<div class="btn-group btn-group-xs"></div>')
                .append($('<button type="button" class="btn btn-secondary many-em-toolbar-button" data-many-em-action="update-instances"></button>')
                    .html('Update') // tt-fy
                    .on('click', updateInstances)
                )
                .append('<button type="button" class="btn btn-secondary dropdown-toggle dropdown-toggle-split many-em-dropdown-toggle-update" data-toggle="dropdown"></button')
                .append('<div class="dropdown-menu many-em-dropdown-update"></div>')
            )
        )
    if (DTO.userRights.lock_record) {
        // Lock and Unlock
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-secondary many-em-toolbar-button" data-many-em-action="lock-record-instances"></button>')
            .text('Lock') // tt-fy
            .on('click', lockUnlockInstances)
        )
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-secondary many-em-toolbar-button" data-many-em-action="unlock-record-instances"></button>')
            .text('Unlock') // tt-fy
            .on('click', lockUnlockInstances)
        )
    }
    if (DTO.userRights.record_delete) {
        // Delete
        $tb.find('.btn-toolbar').append($('<button class="btn btn-xs btn-danger many-em-toolbar-button" data-many-em-action="delete-instances"></button>')
            .text('Delete') // tt-fy
            .on('click', showDeleteInstances)
        )
    }
    // Add view and update presets
    var $viewPresets = $tb.find('.many-em-dropdown-view')
    DTO.rhp.viewPresets.forEach(function(preset) {
        $viewPresets.append($('<a class="dropdown-item" href="javascript:;"></a>')
        .text(preset.name)
        .attr('data-many-em-preset-id', preset.id)
        .on('click', viewInstances)
        )
    })
    var $updatePresets = $tb.find('.many-em-dropdown-update')
    DTO.rhp.updatePresets.forEach(function(preset) {
        $updatePresets.append($('<a class="dropdown-item" href="javascript:;"></a>')
            .text(preset.name)
            .attr('data-many-em-preset-id', preset.id)
            .on('click', updateInstances)
        )
    })
}


/**
 * Replaces a buttons content with a spinner.
 * @param {JQuery<Element>} $btn 
 */
function spinButton($btn) {
    var $temp = $('<div style="display:none;" class="many-em-spinning"></div>')
    $temp.html($btn.html())
    $btn.width($btn.width()) // Preserve width
    $btn.html('<div class="many-em-spinner"><i class="fas fa-spinner fa-pulse"></i></div>')
    $btn.append($temp)
}

/**
 * Restores a spinning button's content.
 * @param {JQuery<Element>} $btn 
 * @param {boolean|null} success 
 */
function unspinButton($btn, success) {
    $btn.find('.many-em-spinner').remove()
    $btn.html($btn.find('.many-em-spinning').html())
    $btn.addClass('many-em-btn-success')
    setTimeout(function() {
        $btn.removeClass('many-em-btn-success')
    }, 500);
}

/**
 * Lock or unlock the selected instances.
 * @param {JQueryEventObject} e 
 */
function lockUnlockInstances(e) {
    var $btn = $(e.target)
    var mode = $btn.attr('data-many-em-action')
    if (mode == 'lock-record-instances' || mode == 'unlock-record-instances') {
        log(DTO.name + ': Locking/Unlocking instances (' + mode + ').')
        // Disable toolbar
        $('.many-em-rit-toolbar button').prop('disabled', true)
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
    updateRepeatInstrumentToolbar()
    // Reload page
    if (jqXHR == null) setTimeout(function() { location.reload() }, 200)
}

/**
 * Show the view instances dialog
 * @param {JQueryEventObject} e 
 */
function viewInstances(e) {
    var presetId = e.target.getAttribute('data-many-em-preset-id')
    
    log('viewInstances for ' + presetId)
}

/**
 * Show the update instances dialog
 * @param {JQueryEventObject} e 
 */
function updateInstances(e) {
    var presetId = e.target.getAttribute('data-many-em-preset-id')
    
    log('updateInstances for ' + presetId)
}

/**
 * Asks for confirmation to delete all selected instances
 */
function showDeleteInstances() {
    // Get confirmation
    if (DTO.userRights.record_delete) {
        var $modal = $('.many-em-delete-confirmation-modal')
        $modal.find('.modal-title').html(DTO.rhp.deleteConfirmTitle)
        $modal.find('.modal-body').html(DTO.rhp.deleteConfirmText)
        $modal.attr('data-many-em-action', 'delete-instances')
        $modal.modal('show')
    }
}

/**
 * Deletes all selected instances and reloads the page
 */
function deleteInstances() {
    if (DTO.userRights.record_delete) {
        log('Many EM - Deleting instances:', manyInstances)
        // Disable buttons.
        $('.many-em-delete-confirmation-modal button').prop('disabled', true)
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
    var $modal = $('.many-em-delete-confirmation-modal button')
    // Disable buttons.
    $modal.prop('disabled', true)
    $modal.find('.modal-body').append('Failed')
    // TODO

}

/**
 * Restores checkboxes to reflect the state in Instance Selection
 */
function restoreInstances() {
    Object.keys(DTO.rhp.rit).forEach(function(rit) {
        var $rit = $('#' + rit)
        $rit.find('input.many-em-toggle-all').prop('checked', false)
        $rit.find('input[data-many-em-instance]').each(function() {
            var $cb = $(this)
            var instance = parseInt($cb.attr('data-many-em-instance'))
            $cb.prop('checked', typeof manyInstances[rit] != 'undefined' && manyInstances[rit][instance] == true)
        })
    })
}

/**
 * Removes all instances from the Instances Selection
 */
function clearInstances() {
    updateServerInstances('remove-all-instances', '--', null, null, null)
    manyInstances = {}
    restoreInstances()
    updateRepeatInstrumentToolbar()
}

/**
 * Determines the number of total selected instances and updates the counter in the
 * repeat instrument toolbar
 */
function updateRepeatInstrumentToolbar() {
    var count = 0
    Object.keys(manyInstances).forEach(function(rit) {
        Object.keys(manyInstances[rit]).forEach(function(instance) {
            if (manyInstances[rit][instance]) {
                count++
            }
        })
    })
    $('.many-em-instances-total-count').text(count)
    $('.many-em-toolbar-button').prop('disabled', count == 0)
    $('.many-em-dropdown-toggle-view').prop('disabled', count == 0 || !DTO.rhp.viewPresets.length)
    $('.many-em-dropdown-toggle-update').prop('disabled', count == 0 || !DTO.rhp.updatePresets.length)

}

/**
 * Adds Many UI to all repeating instrument tables
 */
function setupRecordHomePage() {
    // Setup UI for each repeating instrument table
    Object.keys(DTO.rhp.rit).forEach(function(rit) {
        // Initialize Selected Instances store
        manyInstances[rit] = {}
        DTO.rhp.rit[rit].forEach(function(instance) {
            manyInstances[rit][instance] = true
        })
        // Set initial state to hidden
        rhpState.visible[rit] = false
        // Build HTML
        var $rit = buildRepeatInstrumentTableMenu(rit)
        // Hook up events
        $rit.find('a[data-many-em-action]').on('click', function() {
            var command = this.getAttribute('data-many-em-action')
            switch(command) {
                case 'toggle': {
                    toggleRepeatInstrumentTableMenu(rit)
                    break
                }
                case 'apply': {
                    applyRHPinstances(rit)
                    break
                }
                case 'addAll': {
                    $rit.find('input[data-many-em-instance]').prop('checked', true)
                    applyRHPinstances(rit)
                    break
                }
                case 'removeAll': {
                    $rit.find('input[data-many-em-instance]').prop('checked', false)
                    applyRHPinstances(rit)
                    break
                }
            }
        })
        // Update count
        $rit.find('.many-em-rit-instance-count').text(DTO.rhp.rit[rit].length)
        if (DTO.rhp.activate && rhpState.record_selected) toggleRepeatInstrumentTableMenu(rit)
    })
    // Build the repeating instruments toolbar
    buildRepeatInstrumentToolbar()
    // Is the record in the selection?
    rhpState.record_selected = manyRecords[rhpState.record] == true
    if (rhpState.record_selected) {
        $('.many-em-rit').show()
        updateRepeatInstrumentToolbar()
    }
    // Hook up modal events
    $('.many-em-delete-confirmation-modal button.many-em-confirmed').on('click', deletionConfirmed)
    // Remove max width - TODO - this is not perfect
    $('#repeating_forms_table_parent').children('div').css('max-width', '33%').css('flex', '0 0 33%')
}

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
    manyRecords[rhpState.record] = rhpState.record_selected
    diff[rhpState.record] = rhpState.record_selected
    if (rhpState.record_selected) {
        $('.many-em-rit').show(100)
        if (DTO.rhp.activate) {
            $('.many-em-toggle-display').show()
        }
    }
    else {
        // Clear all instances
        $('.many-em-toggle-display').hide()
        $('.many-em-rit').hide(100)
        $('input[data-many-em-instance]').prop('checked', false)
        Object.keys(DTO.rhp.rit).forEach(function(rit) {
            rhpState.visible[rit] = false
            applyRHPinstances(rit)
        })
    }
    updateServerSelection('update-records', diff)
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
        rhpState.record_selected = manyRecords[rhpState.record] == true
    }
    log('Many EM - Record: ' + rhpState.record + (rhpState.record_selected ? ' (selected)' : ''))
}

//#endregion



//#region -- Modal Events -----------------------------------------------------------------

function deletionConfirmed() {
    var action = $('.many-em-delete-confirmation-modal').attr('data-many-em-action')
    if (action == 'delete-instances') {
        deleteInstances()
    }
}

//#endregion



function toggleAll() {
    $('input[data-many-em-record]').prop('checked', 
        $('input.many-em-toggle-all').prop('checked'))
}


//#region -- Record Selection ------------------------------------------------------------

/**
 * Adds all currently checked dashboard records to the Record Selection and
 * removes all that are not checked
 */
function applyDashboardRecordSelection() {
    /** @type {UpdateDiff} */
    var diff = {}
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        var checked = $cb.prop('checked')
        if (typeof manyRecords[id] == 'undefined') {
            manyRecords[id] = checked
            diff[id] = checked
        }
        else {
            if (manyRecords[id] != checked) {
                diff[id] = checked
            }
            manyRecords[id] = checked
        }
    })
    updateServerSelection('update-records', diff)
    log('Many EM - Updated selection')
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
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manyRecords[id] = true
        $cb.prop('checked', true)
    })
    updateServerSelection('update-records', manyRecords)
    log('Many EM - Added all')
}

/**
 * Removes all dashboard records from the Record Selection
 */
function removeAllDashboardRecords() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manyRecords[id] = false
        $cb.prop('checked', false)
    })
    updateServerSelection('remove-all-records', null)
    log('Many EM - Removed all')
}

/**
 * Complete clears the Record Selection
 */ 
function clearAllRecords() {
    manyRecords = {}
    if (rhpState.record_selected) {
        addRemoveRecord(false)
    }
    else {
        updateServerSelection('remove-all-records', null)
    }
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
        updateDataCollectionLink()
        log('Many EM - Records updated. Currently selected:', manyRecords)
    })
    .fail(function(jqXHR, textStatus, errorThrown) {
        log(jqXHR, textStatus, errorThrown)
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
    log('Many EM - Ajax Initiated:', data)
    $.ajax({
        url: DTO.updateUrl,
        type: 'POST',
        data: 'payload=' + JSON.stringify(data),
        dataType: 'json'
    })
    .done(function(data, textStatus, jqXHR) {
        log('Many EM - Ajax Success:', jqXHR)
        if (callbackDone) callbackDone()
    })
    .fail(function(jqXHR, textStatus, errorThrown) {
        log('Many EM - Ajax Failure: ', jqXHR, textStatus, errorThrown)
        if (callbackFail) callbackFail(jqXHR)
    })
}

//#endregion


$(function() {
    // Page has loaded - init stuff.
    log('Many EM - Initializing', DTO)
    // Setup selection object.
    manyRecords = {}
    DTO.selected.forEach(function(id) {
        manyRecords[id] = true
    })
    // Determine state
    if (DTO.rhp.init) determineRecordState()
    addDataCollectionLink()
    if (DTO.rsd.init) setupRecordStatusDashboard()
    if (DTO.rhp.init) setupRecordHomePage()
})

})();