@props([
    'column',
    'activeColumn' => 'default',
    'direction' => 'asc',
])

@php
    $isActive = $activeColumn === $column;
    $isAsc = $isActive && $direction === 'asc';
    $isDesc = $isActive && $direction === 'desc';

    $icon = $isAsc ? 'bi-arrow-up' : ($isDesc ? 'bi-arrow-down' : 'bi-arrow-down-up');
    $colorClass = $isActive ? 'text-primary' : 'text-muted';
@endphp

<i {{ $attributes->merge(['class' => "bi {$icon} {$colorClass}"]) }}></i>
