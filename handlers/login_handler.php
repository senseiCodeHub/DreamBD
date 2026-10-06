<?php
ob_start();

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth_functions.php';

dream_start_session();

$auth = new Auth();
$security = new Security();

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => '',
    'errors' => [],
    'redirect' => null,
    'clearForm' => false
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token. Please try again.';
        $response['errors']['global'] = 'Invalid security token. Please try again.';
    }
    // [RECAPTCHA-TEMP-DISABLED] re-enable by uncommenting the block below
    // elseif (!$security->validateRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
    //     $response['message'] = 'Please complete the reCAPTCHA verification.';
    //     $response['errors']['global'] = 'Please complete the reCAPTCHA verification.';
    //     $response['errors']['recaptcha'] = 'required';
    // }
    else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);

        if (empty($identifier) || empty($password)) {
            $response['message'] = 'Please fill in all fields';
            $response['errors']['global'] = 'Please fill in all fields';
        } else {
            $result = $auth->login($identifier, $password, $remember_me);

            if ($result['success']) {
                $response['success'] = true;
                $response['message'] = 'Login successful! Redirecting...';
                $response['redirect'] = 'index.php?page=profile';
                $response['clearForm'] = true;

                if (isset($_POST['remember_me'])) {
                    setcookie('remember_identifier', $identifier, time() + (86400 * 30), "/");
                }

                $_SESSION['just_logged_in'] = true;
                session_write_close();
            } else {
                $response['message'] = $result['errors']['global'] ?? 'Login failed. Please check your credentials.';
                $response['errors'] = $result['errors'] ?? ['global' => 'Login failed'];
                $response['email_verified'] = isset($result['errors']['email_verified']) && $result['errors']['email_verified'] === 'false' ? false : true;

                $_SESSION['form_errors'] = $response['errors'];
            }
        }
    }
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

echo json_encode($response);
exit;
