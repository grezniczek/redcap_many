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
 *  link: RecordLinkDTO
 *  selected: string[]
 *  debug: boolean
 *  rsd: RecordStatusDashboardDTO
 *  rhp: RecordHomePageDTO
 *  updateUrl: string
 * }}
 */

/**
 * @typedef RecordStatusDashboardDTO
 * @type {{
 *  init: boolean
 *  activate: boolean
 *  updateSelection: string
 *  restore: string
 *  addAll: string
 *  removeAll: string
 * }}
 */

/**
 * @typedef RecordHomePageDTO
 * @type {{
 *  init: boolean
 *  rit: string[]
 * }}
 */

/**
 * @typedef RecordLinkDTO
 * @type {{
 *  href: string
 *  name: string
 *  clearText: string
 *  addText: string
 *  removeText: string
 * }}
 */

/**
 * @typedef RecordLinkState
 * @type {{
 *  $counter: JQuery
 *  $clear: JQuery
 * }}
 */

/**
 * @typedef RecordLink
 * @type {{
 *  $counter?: JQuery
 *  $clear?: JQuery
 * }}
 */

/**
 * @typedef RecordStatusDashboardState
 * @type {{
 *  visible: boolean
 *  $statusBarToggle: JQuery
 *  $toggleAllCheckbox: JQuery
 * }}
 */

/**
 * @typedef RecordHomePageState
 * @type {{
 *  record: string
 *  $addRemoveLink: JQuery
 *  visible: Object<string, boolean>
 * }}
 */

/**
 * @typedef ManyRecords
 * @type {Object<string, boolean>}
 */

/**
 * @typedef ManyInstances
 * @type {Object<string, Object<string, boolean>>}
 */
