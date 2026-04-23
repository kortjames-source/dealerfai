<?php
declare(strict_types=1);

/**
 * Normalize the `roles` value stored in the session so downstream checks always work.
 */
function load_session_roles(): array
{
    $roles = $_SESSION['roles'] ?? [];
    if (is_string($roles)) {
        $decoded = json_decode($roles, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [];
    }

    if (!is_array($roles)) {
        return [];
    }

    return $roles;
}

function get_accessible_organizations(): array
{
    $orgs = $_SESSION['accessible_orgs'] ?? [];
    if (!is_array($orgs)) {
        return [];
    }
    $normalized = [];
    foreach ($orgs as $org) {
        if (is_int($org) && $org > 0) {
            $normalized[] = $org;
            continue;
        }
        if (is_string($org) && ctype_digit($org)) {
            $orgId = (int)$org;
            if ($orgId > 0) {
                $normalized[] = $orgId;
            }
        }
    }
    return $normalized;
}

function get_admin_organization_context(): ?int
{
    $context = $_SESSION['admin_org_context'] ?? null;
    if ($context === '' || $context === null) {
        return null;
    }
    return (int)$context;
}

function set_admin_organization_context(?int $orgId): void
{
    if ($orgId === null || $orgId <= 0) {
        unset($_SESSION['admin_org_context']);
        return;
    }
    $_SESSION['admin_org_context'] = $orgId;
}

function get_effective_organization(): ?int
{
    $adminContext = get_admin_organization_context();
    if ($adminContext !== null) {
        return $adminContext;
    }

    $orgContext = $_SESSION['organization'] ?? null;
    if ($orgContext === '' || $orgContext === null) {
        return null;
    }

    if (is_int($orgContext) || (is_string($orgContext) && ctype_digit($orgContext))) {
        return (int)$orgContext;
    }

    return null;
}

function get_admin_alert_count(PDO $db): int
{
    $roles = load_session_roles();
    if (!in_array('Admin', $roles, true)) {
        return 0;
    }

    try {
        $stmt = $db->query("SELECT COUNT(*) FROM admin_error_alerts WHERE is_resolved = 0");
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
