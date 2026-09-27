import Popper from "popper.js";
// import * as bootstrap from 'bootstrap';
import Alpine from 'alpinejs';

/**
 * Set up global libraries. Alpine is started from app.js after all
 * Alpine-powered components are registered to avoid race conditions.
 */
try {
    window.Popper = Popper;
} catch (e) {
    console.log(e);
}

window.Alpine = Alpine;

window.Alpine = Alpine;

export { Alpine };
