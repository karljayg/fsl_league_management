<?php
/**
 * Active / inactive status badges for players and teams.
 * Team Status uses 'defunct' for inactive teams.
 */

function fsl_is_active_status(?string $status): bool
{
    $value = strtolower(trim((string) $status));
    return $value === '' || $value === 'active';
}

function fsl_status_badge(?string $status): string
{
    $active = fsl_is_active_status($status);
    $class = $active ? 'fsl-status-badge fsl-status-badge--active' : 'fsl-status-badge fsl-status-badge--inactive';
    $label = $active ? 'ACTIVE' : 'INACTIVE';
    return '<span class="' . $class . '">' . $label . '</span>';
}

function fsl_status_badge_css(): string
{
    return <<<'CSS'
.fsl-status-badge {
    display: inline-block;
    font-weight: 800;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    padding: 0.22rem 0.55rem;
    border-radius: 4px;
    line-height: 1.25;
    text-transform: uppercase;
    vertical-align: middle;
    white-space: nowrap;
}
.fsl-status-badge--active {
    background: #43a047;
    color: #000;
}
.fsl-status-badge--inactive {
    background: #e53935;
    color: #000;
}
CSS;
}
