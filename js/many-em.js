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
    $menu.find('.many-em-menu-link-count').after($('<a></a>')
        .addClass('many-em-menu-link-clear')
        .attr('href', 'javascript:;')
        .text(DTO.link.clearText)
        .on('click', clearSelection))
    rls.$counter = $menu.find('.many-em-menu-link-count')
    rls.$clear = $menu.find('.many-em-menu-link-clear')
    if (DTO.rhp.init) {
        rls.$clear.after($('<a></a>')
            .addClass('many-em-menu-link-addremoverecord')
            .attr('href', 'javascript:;')
            .on('click', addRemoveRecord))
        .after(' &mdash; ')
        rhpState.$addRemoveLink = $menu.find('.many-em-menu-link-addremoverecord')
    }
    updateLink()
}

function addInstancesMenu() {
    $('#record-home-link').parent().parent().parent()
}

function toggleInstances() {

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
                    .on('click', updateSelection)
                    .text(DTO.rsd.updateSelection))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', addAll)
                    .text(DTO.rsd.addAll))
                .append('&nbsp; | &nbsp;')
                .append($('<a href="javascript:;"></a>')
                    .on('click', removeAll)
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


function applyRHPinstances(rit) {
    var $rit = $('#' + rit)
    var count = 0
    // Update data
    $rit.find('input[data-many-em-instance]').each(function() {
        var $cb = $(this)
        if (typeof manyInstances[rit] == 'undefined') {
            manyInstances[rit] = {}
        }
        var checked = $cb.prop('checked')
        var instance = $cb.attr('data-many-em-instance')
        manyInstances[rit][instance] = checked
        if (checked) count++
    })
    // Update counter
    $rit.find('.many-em-rit-instance-count').text(count)
}

/**
 * Adds Many UI to all repeating instrument tables.
 */
function setupRecordHomePage() {
    DTO.rhp.rit.forEach(function(rit) {
        rhpState.visible[rit] = false
        var parts = rit.split('-')
        var event_id = parts[1]
        var form_name = parts[2]
        var $rit = $('#' + rit)
        if ($rit.length) {
            // Add menu
            $rit.find('span.repeat_event_count_menu').after(
                $('<span class="many-em-rit"></span>')
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
        }
    })
}

function toggleAll() {
    $('input[data-many-em-record]').prop('checked', 
        $('input.many-em-toggle-all').prop('checked'))
}

function updateSelection() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        var checked = $cb.prop('checked')
        manyRecords[id] = checked
    })
    updateServerSelection()
    log('Many EM - Updated selection')
}


function addAll() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manyRecords[id] = true
        $cb.prop('checked', true)
    })
    updateServerSelection()
    log('Many EM - Added all')
}

function removeAll() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manyRecords[id] = false
        $cb.prop('checked', false)
    })
    updateServerSelection()
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

function addRemoveRecord() {
    rhpState.$addRemoveLink.hide()
    if (manyRecords[rhpState.record]) {
        manyRecords[rhpState.record] = false
    }
    else {
        manyRecords[rhpState.record] = true
    }
    updateServerSelection()
}

function clearSelection() {
    updateServerSelection({})
}

function updateServerSelection(data) {
    if (typeof data == 'undefined') data = manyRecords
    var selected = Object.keys(data).filter(function(key) {
        return data[key]
    })
    $.ajax({
        url: DTO.updateUrl,
        type: 'POST',
        data: 'payload=' + JSON.stringify(selected),
        dataType: 'json'
    })
    .done(function(data, textStatus, jqXHR) {
        setSelected(data)
        if (DTO.rsd.init) updateRecordStatusDashboardSelection()
        updateLink()
        log('Many EM - Selection cleared.', data)
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