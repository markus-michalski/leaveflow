import './stimulus_bootstrap.js';
// CSS is loaded via <link> in base.html.twig to avoid unused-preload warnings and a circular @source reference.
import '@hotwired/turbo';
import { initFlowbite } from 'flowbite';

// Turbo swaps the <body> on navigation, which means Flowbite's DOMContentLoaded
// initialisation runs only on the first load. Re-init after each Turbo visit so
// dropdowns/modals/tooltips wired via data-* attributes work on subsequent pages.
document.addEventListener('turbo:load', () => initFlowbite());

