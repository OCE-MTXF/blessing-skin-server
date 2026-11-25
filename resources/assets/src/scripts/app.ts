import './init' // The boot data must exist before loading app modules.
import $ from 'jquery'
import 'bootstrap'
import './extra'
import './i18n'
import './net'
import './event'
import './notification'
import './emailVerification'
import './logout'
import './darkMode'
import { initSidebar } from './sidebar'

initSidebar()
// Initialize only the markup tooltips. Bootstrap's default `html: false` is
// made explicit so plugin or translated text is never interpreted as markup.
$('[data-toggle="tooltip"]').tooltip({ html: false })
