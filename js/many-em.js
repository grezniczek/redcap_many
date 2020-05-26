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

function addRecordLink() {
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
            .on('click', clearSelection)
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
    updateLink()
}

function updateLink() {
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
        if (manyRecords[rhpState.record]) {
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
                    .on('click', applyRecordSelection)
                    .text(DTO.rsd.apply))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', restoreRecordSelection)
                    .text(DTO.rsd.restore))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', addAllRecords)
                    .text(DTO.rsd.addAll))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', removeAllRecords)
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
        var instance = $cb.attr('data-many-em-instance')
        manyInstances[rit][instance] = checked
        diff[instance] = checked
        if (checked) count++
    })
    // Update server
    if (count == 0) {
        updateServerInstances('remove-all-instances', rit, null)
    }
    else {
        updateServerInstances('update-instances', rit, diff)
    }
    // Update counter
    $rit.find('.many-em-rit-instance-count').text(count)
}

/**
 * Adds Many UI to all repeating instrument tables.
 */
function setupRecordHomePage() {
    // Setup UI
    Object.keys(DTO.rhp.rit).forEach(function(rit) {
        manyInstances[rit] = {}
        DTO.rhp.rit[rit].forEach(function(instance) {
            manyInstances[rit][instance] = true
        })
        rhpState.visible[rit] = false
        var parts = rit.split('-')
        var event_id = parts[1]
        var form_name = parts[2]
        var $rit = $('#' + rit)
        if ($rit.length) {
            // Add menu
            $rit.find('span.repeat_event_count_menu').after(
                $('<span class="many-em-rit" style="display:none;"></span>')
                    .attr('data-many-em-event-id', event_id)
                    .attr('data-many-em-form-name', form_name)
                    .append(getManyIconHTML())
                    .append(' ')
                    .append($('<a href="javascript:;" data-many-em-action="toggle"></a>')
                        .text(DTO.name)
                        .on('click', function() {
                            rhpState.visible[rit] = !rhpState.visible[rit]
                            if (rhpState.visible[rit]) {
                                $rit.find('.many-em-toggle-display').show()
                            }
                            else {
                                $rit.find('.many-em-toggle-display').hide()
                            }
                        })
                    )
                    .append('<span class="badge badge-secondary many-em-rit-instance-count">0</span>')
                    .append($('<div class="many-em-rit-menu many-em-toggle-display" style="display:none;"></div>')
                        .append($('<a href="javascript:;" data-many-em-action="apply"></a>')
                            .text('Apply')
                            .on('click', function() {
                                applyRHPinstances(rit)
                            })
                        )
                        .append(' | ')
                        .append($('<a href="javascript:;" data-many-em-action="addAll"></a>')
                            .text('Add all')
                            .on('click', function() {
                                $rit.find('input[data-many-em-instance]').prop('checked', true)
                                applyRHPinstances(rit)
                            })
                        )
                        .append(' | ')
                        .append($('<a href="javascript:;" data-many-em-action="removeAll"></a>')
                            .text('Remove all')
                            .on('click', function() {
                                $rit.find('input[data-many-em-instance]').prop('checked', false)
                                applyRHPinstances(rit)
                            })
                        )
                    )
                )
            // Add checkboxes
            $rit.find('th').attr('colspan', parseInt($rit.find('th').attr('colspan')) + 1)
            $rit.find('td.data').last().attr('colspan', $rit.find('th').attr('colspan'))
            $rit.find('td.labelrc').each(function() {
                var $td = $(this)
                var $tr = $td.parent()
                var instance = $td.text().trim()
                $td.after('<td style="display:none;" class="labelrc many-em-checkbox-col many-em-toggle-display"><div class="many-em-checkbox-wrapper"><input type="checkbox"></div></td>')
                var $cb = $tr.find('input[type=checkbox]')
                $cb.attr('data-many-em-instance', instance)
                   .attr('data-many-em-record', rhpState.record)
                   .prop('checked', typeof manyInstances[rit] != 'undefined' && manyInstances[rit][instance] == true)
                $td.on('click', function(e) {
                    if (rhpState.visible[rit] && e.target.nodeName == 'TD') {
                        $cb.prop('checked', !$cb.prop('checked'))
                    }
                })
            })
            // Add extra row for select all checkbox.
            $rit.find('th').parent().after('<tr class="many-em-toggle-display" style="display:none;"><td class="labelrc">&nbsp;</td><td class="labelrc many-em-checkbox-col"><div class="many-em-checkbox-wrapper"><input type="checkbox" class="many-em-toggle-all"></div></td><td class="data"></td></tr>')
            $rit.find('input.many-em-toggle-all').on('change', function(e) {
                var checked = $(e.target).prop('checked')
                $rit.find('input[data-many-em-instance]').prop('checked', checked)
            })
            // Update count
            $rit.find('.many-em-rit-instance-count').text(DTO.rhp.rit[rit].length)
        }
    })
    // Is the record in the selection?
    rhpState.record_selected = manyRecords[rhpState.record] == true
    if (rhpState.record_selected) {
        $('.many-em-rit').show()
    }
}

function toggleAll() {
    $('input[data-many-em-record]').prop('checked', 
        $('input.many-em-toggle-all').prop('checked'))
}

function applyRecordSelection() {
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


function addAllRecords() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manyRecords[id] = true
        $cb.prop('checked', true)
    })
    updateServerSelection('update-records', manyRecords)
    log('Many EM - Added all')
}

function removeAllRecords() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manyRecords[id] = false
        $cb.prop('checked', false)
    })
    updateServerSelection('remove-all-records', null)
    log('Many EM - Removed all')
}

function updateRecordStatusDashboardSelection() {
    rsdState.$toggleAllCheckbox.prop('checked', false)
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        $cb.prop('checked', manyRecords[id] == true)
    })
}

/**
 * Adds or removes the current record to the record selection.
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
    }
    else {
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

function clearSelection() {
    manyRecords = {}
    if (rhpState.record_selected) {
        addRemoveRecord(false)
    }
    else {
        updateServerSelection('remove-all-records', null)
    }
}

function restoreRecordSelection() {
    updateRecordStatusDashboardSelection()
}

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
        updateLink()
        log('Many EM - Records updated.')
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
 */
function updateServerInstances(cmd, rit, diff) {
    var parts = rit.split('-')
    var data = {
        command: cmd,
        record: rhpState.record,
        event: parts[1],
        form: parts[2],
        diff: diff
    }
    $.ajax({
        url: DTO.updateUrl,
        type: 'POST',
        data: 'payload=' + JSON.stringify(data),
        dataType: 'json'
    })
    .done(function(data, textStatus, jqXHR) {
        log('Many EM - Instances update (' + rit + ').')
    })
    .fail(function(jqXHR, textStatus, errorThrown) {
        log(jqXHR, textStatus, errorThrown)
    })
}

function setSelected(list) {
    manyRecords = {}
    list.forEach(function(id) {
        manyRecords[id] = true
    })
}

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
    }
    log('Many EM - Record = ' + rhpState.record)
}

$(function() {
    // Page has loaded - init stuff.
    log('Many EM - Initializing', DTO)
    // Setup selection object.
    setSelected(DTO.selected)
    log('Currently selected:', manyRecords)
    // Determine state
    if (DTO.rhp.init) determineRecordState()
    
    addRecordLink()
    if (DTO.rsd.init) setupRecordStatusDashboard()
    if (DTO.rhp.init) setupRecordHomePage()
})

})();