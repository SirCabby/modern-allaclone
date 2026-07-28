@props(['id', 'label' => 'ID'])
{{-- Database id beside a page title; click copies the bare id for GM commands. --}}
<button type="button" data-id="{{ $id }}"
    onclick="navigator.clipboard?.writeText(this.dataset.id)"
    class="badge badge-soft badge-info font-mono text-sm align-middle cursor-pointer"
    title="Click to copy {{ $id }}">
    {{ $label }}: {{ $id }}
</button>
