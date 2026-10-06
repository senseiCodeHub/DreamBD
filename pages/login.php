<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/security.php';

$security = new Security();
$csrf_token = $security->generateCSRFToken();

$saved_identifier = '';
if (isset($_COOKIE['remember_identifier'])) {
    $saved_identifier = htmlspecialchars($_COOKIE['remember_identifier']);
}

$errors = [];
if (isset($_SESSION['form_errors'])) {
    $errors = $_SESSION['form_errors'];
    unset($_SESSION['form_errors']);
}

$success_msg = '';
$success_type = '';
if (isset($_GET['registered']) && $_GET['registered'] === 'true') {
    $success_msg = 'Registration successful! Check your email to verify your account.';
    $success_type = 'success';
}
if (isset($_GET['verified']) && $_GET['verified'] === 'true') {
    $success_msg = 'Email verified! You can now log in.';
    $success_type = 'success';
}
if (isset($_GET['reset']) && $_GET['reset'] === 'true') {
    $success_msg = 'Password reset successfully! Log in with your new password.';
    $success_type = 'success';
}
?>
<div class="min-h-screen flex items-center justify-center p-4 bg-gray-100 dark:bg-gray-900" id="authPage">
    <div class="w-full max-w-sm">

        <!-- Card -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6">
            <!-- Header -->
            <div class="text-center mb-6">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-600 rounded-full mb-3">
                    <i class="fas fa-sign-in-alt text-lg text-white"></i>
                </div>
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">Sign In</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Welcome back to DreamBD</p>
            </div>

            <!-- Success Message -->
            <?php if ($success_msg): ?>
            <div class="flex items-start gap-2 p-3 mb-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg text-sm">
                <i class="fas fa-check-circle text-green-500 mt-0.5 flex-shrink-0"></i>
                <span class="text-green-700 dark:text-green-300"><?php echo htmlspecialchars($success_msg); ?></span>
            </div>
            <?php endif; ?>

            <!-- Error -->
            <?php if (isset($errors['global'])): ?>
            <div class="flex items-start gap-2 p-3 mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-sm" id="globalError">
                <i class="fas fa-exclamation-circle text-red-500 mt-0.5 flex-shrink-0"></i>
                <span class="text-red-700 dark:text-red-300"><?php echo htmlspecialchars($errors['global']); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="handlers/login_handler.php" id="loginForm" data-ajax-form="true" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="space-y-4">
                    <!-- Identifier -->
                    <div>
                        <label for="identifier" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email, username, or phone</label>
                        <input type="text" id="identifier" name="identifier" required autocomplete="username"
                               class="block w-full px-3 py-2.5 border <?php echo isset($errors['identifier']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                               placeholder="you@example.com"
                               value="<?php echo htmlspecialchars($saved_identifier); ?>">
                        <?php if (isset($errors['identifier'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['identifier']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
                        <div class="relative">
                            <input type="password" id="password" name="password" required autocomplete="current-password"
                                   class="block w-full px-3 py-2.5 pr-10 border <?php echo isset($errors['password']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                                   placeholder="Enter your password">
                            <button type="button" class="password-toggle absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-500 p-1" data-target="password" aria-label="Show password" tabindex="-1">
                                <i class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['password'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['password']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- reCAPTCHA [RECAPTCHA-TEMP-DISABLED] -->
                    <!--
                    <div class="recaptcha-wrap">
                        <div class="g-recaptcha" data-sitekey="<?php echo DatabaseConfig::RECAPTCHA_SITE_KEY; ?>"></div>
                        <div id="recaptchaError" class="text-xs text-red-500 hidden"></div>
                    </div>
                    -->
                    <div id="recaptchaError" class="text-xs text-red-500 hidden"></div>

                    <!-- Options -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="remember_me" id="remember_me" class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Remember me</span>
                        </label>
                        <a href="#" class="text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 hover:underline" data-modal="forgotPassword">Forgot password?</a>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" id="loginBtn">
                        <span class="btn-text">Sign In</span>
                        <span class="btn-loader hidden"><i class="fas fa-spinner fa-spin"></i> Signing in...</span>
                    </button>
                </div>
            </form>

            <!-- Divider -->
            <div class="flex items-center gap-3 my-5">
                <div class="flex-1 border-t border-gray-200 dark:border-gray-700"></div>
                <span class="text-xs text-gray-400">or</span>
                <div class="flex-1 border-t border-gray-200 dark:border-gray-700"></div>
            </div>

            <!-- Register link -->
            <a href="index.php?page=register" class="block w-full py-2.5 px-4 text-center text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                <i class="fas fa-user-plus mr-1.5"></i>Create New Account
            </a>
        </div>

        <p class="text-center text-xs text-gray-400 mt-4">&copy; 2026 DreamBD</p>
    </div>
</div>
