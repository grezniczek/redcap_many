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

/** @type {MenuLink} */
var MenuLink = {}

/** @type {JQuery} */
var $rsdToggle

/** @type {ManySelection} */
var manySelected = {}

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



function addLink() {
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
    MenuLink.$counter = $menu.find('.many-em-menu-link-count')
    MenuLink.$clear = $menu.find('.many-em-menu-link-clear')
    updateLink()
}


function updateLink() {
    var count = 0
    Object.keys(manySelected).forEach(function(key) {
        if (manySelected[key]) count++
    })
    MenuLink.$counter.text(count)
    if (count) {
        MenuLink.$clear.show()
    }
    else {
        MenuLink.$clear.hide()
    }
}

function toggleRecordStatusDashboardCheckBoxes() {
    var $table = $('#record_status_table')
    var $toggle = $('.many-em-toggle-display')
    if ($rsdToggle.hasClass('statuslink_unselected')) {
        // Show checkboxes
        $rsdToggle.removeClass('statuslink_unselected').addClass('statuslink_selected')
        $toggle.show()
        if (!$toggle.length) {
            // Add column.
            $table.find('thead th').first().after($('<th class="many-em-checkbox-col many-em-toggle-display"></th>')
                .append($('<input type="checkbox" class="many-em-toggle-all"/>')
                    .on('change', toggleAll)))
            $table.find('tbody tr').each(function() {
                var $tr = $(this)
                var href = $tr.find('a').first().attr('href')
                var id = href.split('&id=')[1]
                if (id.includes('&')) id = id.split('&')[0]
                id = decodeURI(id)
                $tr.find('td').first().after('<td class="many-em-checkbox-col many-em-toggle-display"><input data-many-em-record="' + id + '" type="checkbox"></td>')
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
    else {
        // Remove (hide) checkboxes
        $rsdToggle.removeClass('statuslink_selected').addClass('statuslink_unselected')
        $toggle.hide()
    }
    log('Many EM - Toggled checkboxes.')
}

function setupRecordStatusDashboard() {
    var $icon = $('<i></i>')
        .addClass('far fa-check-square fs12 many-em-logo')
    $rsdToggle = $('<a></a>')
        .addClass('statuslink_unselected')
        .attr('href', 'javascript:;')
        .text(DTO.name)
        .on('click', toggleRecordStatusDashboardCheckBoxes)
    $('a.statuslink_unselected').parent().find('a').first().before($rsdToggle)
    $rsdToggle.before($icon)
    $icon.after(' ')
    $rsdToggle.after('&nbsp; | &nbsp;')
    // Auto-show?
    if (DTO.rsd.activate) toggleRecordStatusDashboardCheckBoxes()
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
    $('input[data-many-em-record]').each(function() {
        var $cb = $(this)
        var id = $cb.attr('data-many-em-record')
        $cb.prop('checked', manySelected[id] == true)
    })
}

function clearSelection() {
    updateServerSelection({})
}

function updateServerSelection(data) {
    if (typeof data == 'undefined') data = manySelected
    $.ajax({
        url: DTO.updateUrl,
        type: 'POST',
        data: 'payload=' + JSON.stringify(data),
        dataType: 'json'
    })
    .done(function(data, textStatus, jqXHR) {
        manySelected = data
        if (DTO.rsd.init) updateRecordStatusDashboardSelection()
        updateLink()
        log('Many EM - Selection cleared.', data)
    })
    .fail(function(jqXHR, textStatus, errorThrown) {
        log(jqXHR, textStatus, errorThrown)
    })
}


$(function() {
    log('Many EM - Initializing', DTO)
    // Setup selection object.
    DTO.selected.forEach(function(id) {
        manySelected[id] = true
    })
    log('Currently selected:', manySelected)
    // Page has loaded - init stuff.
    addLink()
    if (DTO.rsd.init) setupRecordStatusDashboard()
})

})();