//
// Many EM
//
/**
 * @typedef ExternalModules
 * @type {{
 *  ManyEM_DTO?: ManyDTO
 * }}
 */

/**
 * @typedef ManyDTO
 * @type {{
 *  name: string
 *  link: MenuLinkDTO
 *  selected: string[]
 *  debug: boolean
 *  rsd: RecordStatusDashboardDTO
 *  updateUrl: string
 * }}
 */

/**
 * @typedef RecordStatusDashboardDTO
 * @type {{
 *  init: boolean
 *  activate: boolean
 *  updateSelection: string
 *  addAll: string
 *  removeAll: string
 * }}
 */

/**
 * @typedef MenuLinkDTO
 * @type {{
 *  href: string
 *  name: string
 *  clearText: string
 *  $counter: JQuery
 *  $clear: JQuery
 * }}
 */

/**
 * @typedef MenuLink
 * @type {{
 *  $counter?: JQuery
 *  $clear?: JQuery
 * }}
 */

/**
 * @typedef ManySelection
 * @type {Object<string, boolean>}
 */

