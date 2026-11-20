import Popper from "popper.js";
// import * as bootstrap from 'bootstrap';

/**
 * Set up global libraries. Livewire supplies and starts Alpine; importing a
 * second instance here disconnects its model bindings from Livewire's state.
 */
try {
    window.Popper = Popper;
} catch (e) {
    console.log(e);
}
