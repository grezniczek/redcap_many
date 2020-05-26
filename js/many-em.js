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

/** @type {ManySelection} */
var manySelected = {}

/** @type {RecordStatusDashboardState} */
var rsdState = {
    visible: false,
    $statusBarToggle: null,
    $toggleAllCheckbox: null
}

/** @type {RecordHomePageState} */
var rhpState = {
    $addRemoveLink: null,
    record: null
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



function addRecordLink() {
    var $menu = $('<div></div>')
        .addClass('hang')
        .css('position', 'relative')
        .append($('<i></i>').addClass('far fa-check-square fs14 many-em-logo'))
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
    Object.keys(manySelected).forEach(function(key) {
        if (manySelected[key]) count++
    })
    rls.$counter.text(count)
    if (count) {
        rls.$clear.show()
    }
    else {
        rls.$clear.hide()
    }
    if (DTO.rhp.init) {
        if (manySelected[rhpState.record]) {
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
    var $icon = $('<i></i>')
        .addClass('far fa-check-square fs12 many-em-logo')
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

function setupRecordHomePage() {
    addInstancesMenu()
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
        manySelected[id] = checked
    })
    updateServerSelection()
    log('Many EM - Updated selection')
}


function addAll() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manySelected[id] = true
        $cb.prop('checked', true)
    })
    updateServerSelection()
    log('Many EM - Added all')
}

function removeAll() {
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        manySelected[id] = false
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
        $cb.prop('checked', manySelected[id] == true)
    })
}

function addRemoveRecord() {
    rhpState.$addRemoveLink.hide()
    if (manySelected[rhpState.record]) {
        manySelected[rhpState.record] = false
    }
    else {
        manySelected[rhpState.record] = true
    }
    updateServerSelection()
}

function clearSelection() {
    updateServerSelection({})
}

function updateServerSelection(data) {
    if (typeof data == 'undefined') data = manySelected
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
    manySelected = {}
    list.forEach(function(id) {
        manySelected[id] = true
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
    log('Currently selected:', manySelected)
    // Determine state
    if (DTO.rhp.init) determineRecordState()
    
    addRecordLink()
    if (DTO.rsd.init) setupRecordStatusDashboard()
    if (DTO.rhp.init) setupRecordHomePage()
})

})();