{{-- "+ Satır Ekle" under ALL product rows (order + purchase forms share it). It only calls the form's own
     addItem(). Plain CSS on purpose (no dependency on the Tailwind build).
     >= 900px: compact and left-aligned with the first row's left edge; below that: centred with a
     touch-sized target. --}}
<style>
    .add-row { display: flex; justify-content: center; margin-top: .75rem; }
    .add-row-btn { min-height: 2.75rem; padding: .5rem 1.5rem; font-size: .875rem; font-weight: 500; line-height: 1.25rem; color: #4f46e5; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: .375rem; cursor: pointer; }
    .add-row-btn:hover { background: #e0e7ff; }
    .add-row-btn:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; }
    @media (min-width: 900px) {
        .add-row { justify-content: flex-start; margin-top: .5rem; }
        .add-row-btn { min-height: 0; padding: .25rem .75rem; }
    }
</style>
<div class="add-row">
    <button type="button" @click="addItem()" class="add-row-btn">+ Satır Ekle</button>
</div>
