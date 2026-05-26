import './stimulus_bootstrap.js';
// CSS is loaded via <link> in base.html.twig to avoid unused-preload warnings and a circular @source reference.
import '@hotwired/turbo';
import { initFlowbite } from 'flowbite';

// Turbo swaps the <body> on navigation, which means Flowbite's DOMContentLoaded
// initialisation runs only on the first load. Re-init after each Turbo visit so
// dropdowns/modals/tooltips wired via data-* attributes work on subsequent pages.
document.addEventListener('turbo:load', () => initFlowbite());

// When a frame reload receives a response that doesn't contain the expected frame
// (e.g. a session-expired redirect to the login page), fall back to a full page
// visit so the user lands on the correct page instead of seeing a silent error.
document.addEventListener('turbo:frame-missing', (event) => {
    event.preventDefault();
    const { response, visit } = event.detail;
    visit(response);
});

