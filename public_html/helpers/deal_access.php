<?php
declare(strict_types=1);

require_once __DIR__ . '/session_utils.php';

function dealerfai_session_can_access_deal(array $deal): bool
{
    $dealId = (int)($deal['id'] ?? 0);
    if ($dealId <= 0) {
        return false;
    }

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        $sessionDealId = isset($_SESSION['deal_id']) ? (int)$_SESSION['deal_id'] : 0;
        return $sessionDealId > 0 && $sessionDealId === $dealId;
    }

    $roles = load_session_roles();
    $isAdmin = in_array('Admin', $roles, true);
    if ($isAdmin) {
        return true;
    }

    $isManager = in_array('General Manager', $roles, true)
        || in_array('Finance Manager', $roles, true)
        || in_array('Sales Manager', $roles, true);

    $effectiveOrg = get_effective_organization();
    $accessibleOrgs = get_accessible_organizations();
    $dealOrgId = (int)($deal['organization'] ?? 0);

    $allowedOrg = false;
    if ($effectiveOrg && $dealOrgId === (int)$effectiveOrg) {
        $allowedOrg = true;
    } else {
        foreach ($accessibleOrgs as $orgId) {
            if ((int)$orgId === $dealOrgId) {
                $allowedOrg = true;
                break;
            }
        }
    }

    if (!$allowedOrg) {
        return false;
    }

    if ($isManager) {
        return true;
    }

    return (int)($deal['salesperson_id'] ?? 0) === $userId;
}
