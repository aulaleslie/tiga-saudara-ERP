<?php

namespace Modules\Pos\Tests\Feature;

use Tests\TestCase;

class PosSaveAndNewUiRegressionTest extends TestCase
{
    private function sellView(): string
    {
        return file_get_contents(
            base_path('Modules/Pos/Resources/views/sell.blade.php')
        );
    }

    private function saveDraftBlock(): string
    {
        $view = $this->sellView();
        $this->assertStringContainsString("saveDraftButton.addEventListener('click'", $view);
        $startPos = strpos($view, "saveDraftButton.addEventListener('click'");
        $endPos = strpos($view, "const saveSuccessContinueBtn", $startPos);
        return substr($view, $startPos, $endPos - $startPos);
    }

    public function test_save_draft_handler_renders_cart_snapshot_directly_without_refresh_cart(): void
    {
        $saveDraftBlock = $this->saveDraftBlock();

        // Assert direct rendering of returned cart_snapshot
        $this->assertStringContainsString('renderCart(response.cart_snapshot)', $saveDraftBlock);

        // Assert absence of refreshCart() in the save-draft handler
        $this->assertStringNotContainsString('refreshCart()', $saveDraftBlock);
    }

    public function test_save_draft_cleanup_does_not_unconditionally_enable_save_button(): void
    {
        $view = $this->sellView();
        $saveDraftBlock = $this->saveDraftBlock();

        // Finally block should restore original text without setting saveDraftButton.disabled = false
        $this->assertStringContainsString('finally {', $saveDraftBlock);
        $finallyBlock = substr($saveDraftBlock, strpos($saveDraftBlock, 'finally {'));
        $this->assertStringNotContainsString('saveDraftButton.disabled = false', $finallyBlock);

        // renderCart owns saveDraftButton.disabled based on validation
        $this->assertStringContainsString('saveDraftButton.disabled = !canSaveDraft;', $view);
    }

    public function test_save_draft_success_cancels_note_debounce_and_invalidates_pending_note_requests(): void
    {
        $view = $this->sellView();
        $saveDraftBlock = $this->saveDraftBlock();

        // Must clear noteDebounceHandle and advance latestNoteRequestId
        $this->assertStringContainsString('clearTimeout(noteDebounceHandle)', $saveDraftBlock);
        $this->assertStringContainsString('noteDebounceHandle = null;', $saveDraftBlock);
        $this->assertStringContainsString('latestNoteRequestId += 1;', $saveDraftBlock);

        // Ensure invalidation happens before rendering snapshot
        $invalidationPos = strpos($saveDraftBlock, 'latestNoteRequestId += 1;');
        $renderPos = strpos($saveDraftBlock, 'renderCart(response.cart_snapshot);');
        $this->assertNotFalse($invalidationPos);
        $this->assertNotFalse($renderPos);
        $this->assertLessThan($renderPos, $invalidationPos);

        // Note response rendering checks generation against latestNoteRequestId
        $this->assertStringContainsString('if (reqId === latestNoteRequestId)', $view);

        // Catch block displays error and restores control states from currentSnapshot
        $catchPos = strpos($saveDraftBlock, '} catch (error) {');
        $this->assertNotFalse($catchPos);
        $catchBlock = substr($saveDraftBlock, $catchPos);
        $this->assertStringNotContainsString('renderCart', $catchBlock);
        $this->assertStringContainsString('setCartStatus(error.message', $catchBlock);
        $this->assertStringContainsString('updateCartControlStates(currentSnapshot)', $catchBlock);
    }

    public function test_save_draft_flushes_or_awaits_pending_note_update_before_saving(): void
    {
        $view = $this->sellView();
        $saveDraftBlock = $this->saveDraftBlock();

        // Must flush debounced note and check noteSaved inside try block before initiating save
        $tryPos = strpos($saveDraftBlock, 'try {');
        $flushPos = strpos($saveDraftBlock, 'const noteSaved = await submitNoteUpdate();');
        $savePos = strpos($saveDraftBlock, 'await jsonRequest(saveAndNewEndpoint');
        $this->assertNotFalse($tryPos);
        $this->assertNotFalse($flushPos);
        $this->assertNotFalse($savePos);
        $this->assertLessThan($flushPos, $tryPos);
        $this->assertLessThan($savePos, $flushPos);

        $this->assertStringContainsString('if (noteDebounceHandle) {', $saveDraftBlock);
        $this->assertStringContainsString('const noteSaved = await submitNoteUpdate();', $saveDraftBlock);
        $this->assertStringContainsString('if (!noteSaved) {', $saveDraftBlock);
        $this->assertStringContainsString('updateCartControlStates(currentSnapshot);', $saveDraftBlock);

        // submitNoteUpdate serializes multiple waiters cleanly by re-entering the gate after awaiting activeNotePromise
        $this->assertStringContainsString('const saved = await activeNotePromise;', $view);
        $this->assertStringContainsString('if (!saved) {', $view);
        $this->assertStringContainsString('return submitNoteUpdate();', $view);

        // submitNoteUpdate tracks desiredNoteValue independently from DOM and loops until latest desired value is saved
        $this->assertStringContainsString('while (desiredNoteValue !== null && desiredNoteValue !== lastSavedNoteValue)', $view);
        $this->assertStringContainsString('lastSavedNoteValue = note;', $view);
        $this->assertStringContainsString('desiredNoteValue = this.value;', $view);

        // Note blur listener must reset noteDebounceHandle to null
        $this->assertStringContainsString("transactionNote.addEventListener('blur'", $view);
        $this->assertStringContainsString('noteDebounceHandle = null;', $view);

        // renderNote only protects unsaved edits (!hasUnsavedNote) allowing authoritative reset
        $this->assertStringContainsString('const hasUnsavedNote =', $view);
        $this->assertStringContainsString('desiredNoteValue !== lastSavedNoteValue', $view);
        $this->assertStringContainsString('if (document.activeElement !== transactionNote && !hasUnsavedNote)', $view);

        // Finally block restores button text even if note update failed early
        $this->assertStringContainsString('saveDraftButton.textContent = originalText;', $saveDraftBlock);
    }
}
