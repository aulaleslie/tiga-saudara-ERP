const fs = require('fs');
const file = 'Modules/Pos/Resources/views/sell.blade.php';
let content = fs.readFileSync(file, "utf8");

const oldHeader = `<div class="card mb-3 border-primary text-primary border shadow-sm">`; // Not found matching.

// Let's use simpler regex blocks to replace

// 1. Header replacement
const headerRegex = /<div class="card mb-3 border-primary">([\s\S]*?)<\/div>(?=\s*<div class="row">)/;
const newHeader = `
        <div class="row section-shell-header">
            <div class="col-12 text-center text-md-left d-md-flex flex-wrap justify-content-center justify-content-md-between p-3 align-items-center mb-3 text-white border-bottom border-primary shadow-sm" style="background:#0d6efd;">
                <div class="flex-grow-1">
                    <h4 class="mb-1 mb-md-0 fw-bold">POS Cashier System <span class="d-print-none small ms-3 opacity-75 px-2 rounded-1 border border-white" style="font-size:0.65rem;">Sesi #{{ $activeSession->id }} @if($activeSession->terminal) • {{ $activeSession->terminal->code }} @endif</span></h4>
                </div>
                <div class="mt-2 mt-md-0 d-print-none">
                    Dibuka: <strong class="badge bg-light text-primary text-uppercase" style="border-radius:4px;">{{ optional($activeSession->opened_at)->format('dM-Y H:i') ?? '-' }}</strong> <span class="badge bg-success ms-1" style="border-radius:4px;">{{ $activeSession->status }}</span>
                </div>
            </div>
        </div>
`;
content = content.replace(headerRegex, newHeader.trim());


// 2. Adjust grid row
content = content.replace(/<div class="row">/, '<div class="row flex-grow-1 align-items-stretch" style="min-height:75vh;">');

const searchRegex = /<div class="col-lg-4 mb-3">/;
content = content.replace(searchRegex, '<div class="col-lg-3 d-flex flex-column mb-3">');

const cartContainerRegex = /<div class="col-lg-5 mb-3">/;
content = content.replace(cartContainerRegex, '<div class="col-lg-6 d-flex flex-column mb-3">');

const paymentRegex = /<div class="col-lg-3 mb-3">/;
// no change needed actually

fs.writeFileSync(file, content);
