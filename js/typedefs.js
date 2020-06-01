//
// Multiple EM
//
/**
 * @typedef ExternalModules
 * @type {{
 *  MultipleEM_DTO?: MultipleDTO
 * }}
 */

/**
 * @typedef MultipleDTO
 * @type {{
 *  name: string
 *  link: RecordLinkDTO
 *  selected: string[]
 *  debug: boolean
 *  rsd: RecordStatusDashboardDTO
 *  rhp: RecordHomePageDTO
 *  updateUrl: string
 *  userRights: UserRightsDTO
 * }}
 */

/**
 * @typedef UserRightsDTO
 * @type {{
 *  design: boolean
 *  record_delete: boolean
 *  lock_record: boolean
 *  lock_record_multiform: boolean
 *  data_access_groups: boolean
 * }}
 */

/**
 * @typedef RecordStatusDashboardDTO
 * @type {{
 *  init: boolean
 *  activate: boolean
 *  apply: string
 *  reset: string
 *  addAll: string
 *  removeAll: string
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
 * @typedef RecordHomePageDTO
 * @type {{
 *  init: boolean
 *  activate: boolean
 *  rit: Object<string, number[]>
 *  fei: string[]
 *  fei_nodata: string[]
 *  nrf: string[] 
 *  viewPresets: InstanceFieldPreset[]
 *  updatePresets: InstanceFieldPreset[],
 *  deleteFormsConfirmTitle: string
 *  deleteFormsConfirmText: string
 * }}
 */

/**
 * @typedef RecordHomePageState
 * @type {{
 *  record: string
 *  record_selected: boolean
 *  $addRemoveLink: JQuery
 *  ritVisible: Object<string, boolean>
 *  visible: boolean
 * }}
 */

/**
 * @typedef InstanceFieldPreset
 * @type {{
 *  id: string
 *  name: string
 *  fields: string[]
 * }}
 */



/**
 * @typedef MultipleRecords
 * @type {Object<string, boolean>}
 */

/**
 * @typedef MultipleInstances
 * @type {Object<string, Object<number, boolean>>}
 */

/**
 * @typedef MultipleForms
 * @type {Object<string, boolean>}
 */


/**
 * @typedef feiDiff
 * @type {Object<string, Object<number, boolean>>}
 */
/**
 * @typedef ritDiff
 * @type {Object<string, Object<string, boolean>>}
 */

/**
 * @typedef RecordDiff
 * @type {Object<string, boolean>}
 */

/**
 * @typedef FormDiff
 * @type {Object<string, Object>}
 */

/**
 * @typedef ModalConfig
 * @type {{
 *  template: JQuery|string
 *  title?: string
 *  body?: string
 *  buttons?: ModalButton[]
 * }}
 */

/**
 * @typedef ModalButton
 * @type {{
 *  label: string
 *  action?: string
 *  addClass?: string[]
 * }}
 */

/**
 * @typedef ModalResult
 * @type {{
 *  action: string
 *  values: Object
 * }}
 */
 

 