<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/security.php';

$security = new Security();
$csrf_token = $security->generateCSRFToken();

$errors = [];
if (isset($_SESSION['form_errors'])) {
    $errors = $_SESSION['form_errors'];
    unset($_SESSION['form_errors']);
}

$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
?>
<div class="min-h-screen flex items-center justify-center p-4 bg-gray-100 dark:bg-gray-900" id="authPage">
    <div class="w-full max-w-md">

        <!-- Card -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6">
            <!-- Header -->
            <div class="text-center mb-6">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-600 rounded-full mb-3">
                    <i class="fas fa-user-plus text-lg text-white"></i>
                </div>
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">Create Account</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Join DreamBD today</p>
            </div>

            <!-- Global Error -->
            <?php if (isset($errors['global'])): ?>
            <div class="flex items-start gap-2 p-3 mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-sm" id="globalError">
                <i class="fas fa-exclamation-circle text-red-500 mt-0.5 flex-shrink-0"></i>
                <span class="text-red-700 dark:text-red-300"><?php echo htmlspecialchars($errors['global']); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="handlers/register_handler.php" id="registerForm" data-ajax-form="true" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="space-y-4">
                    <!-- Full Name -->
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full name</label>
                        <input type="text" id="full_name" name="full_name" required autocomplete="name"
                               class="block w-full px-3 py-2.5 border <?php echo isset($errors['full_name']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                               placeholder="John Doe"
                               value="<?php echo htmlspecialchars($formData['full_name'] ?? ''); ?>">
                        <?php if (isset($errors['full_name'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['full_name']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Username -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Username</label>
                        <input type="text" id="username" name="username" required autocomplete="username"
                               class="block w-full px-3 py-2.5 border <?php echo isset($errors['username']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                               placeholder="johndoe"
                               value="<?php echo htmlspecialchars($formData['username'] ?? ''); ?>">
                        <p class="mt-1 text-xs text-gray-400">3-20 characters, letters &amp; numbers only</p>
                        <?php if (isset($errors['username'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['username']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                        <input type="email" id="email" name="email" required autocomplete="email"
                               class="block w-full px-3 py-2.5 border <?php echo isset($errors['email']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                               placeholder="john@example.com"
                               value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>">
                        <?php if (isset($errors['email'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['email']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
                        <div class="relative">
                            <input type="password" id="password" name="password" required autocomplete="new-password"
                                   class="block w-full px-3 py-2.5 pr-10 border <?php echo isset($errors['password']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                                   placeholder="At least 8 characters">
                            <button type="button" class="password-toggle absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-500 p-1" data-target="password" aria-label="Show password" tabindex="-1">
                                <i class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                        <!-- Strength Meter -->
                        <div id="passwordStrength" class="mt-2 <?php echo isset($errors['password']) ? '' : ''; ?>">
                            <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-300" id="strengthFill" style="width:0%"></div>
                            </div>
                            <div class="flex justify-between text-xs mt-1">
                                <span class="text-gray-400">Strength</span>
                                <span class="font-medium" id="strengthText">None</span>
                            </div>
                        </div>
                        <?php if (isset($errors['password'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['password']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirm password</label>
                        <div class="relative">
                            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password"
                                   class="block w-full px-3 py-2.5 pr-10 border <?php echo isset($errors['confirm_password']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                                   placeholder="Re-enter password">
                            <button type="button" class="password-toggle absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-500 p-1" data-target="confirm_password" aria-label="Show password" tabindex="-1">
                                <i class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                        <div id="passwordMatch" class="mt-1 hidden">
                            <p class="text-xs" id="matchText"></p>
                        </div>
                        <?php if (isset($errors['confirm_password'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['confirm_password']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Phone (optional) -->
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone <span class="text-gray-400">(optional)</span></label>
                        <div class="flex gap-2">
                            <div class="relative w-24 flex-shrink-0" id="countryCodeContainer">
                                <button type="button" id="countryCodeBtn" class="w-full h-full px-2 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors flex items-center justify-between gap-1" style="min-height:42px">
                                    <span id="selectedCode">+1</span>
                                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                                </button>
                                <input type="hidden" id="country_code" name="country_code" value="+1">
                                <div id="countryDropdown" class="hidden absolute z-50 w-56 max-h-52 overflow-y-auto bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg mt-1 left-0">
                                    <input type="text" id="countrySearch" placeholder="Search..." class="w-full px-2 py-1.5 text-sm border-b border-gray-200 dark:border-gray-700 focus:outline-none bg-transparent">
                                    <div id="countryList" class="py-1"></div>
                                </div>
                            </div>
                            <div class="flex-1">
                                <input type="tel" id="phone" name="phone" autocomplete="tel"
                                       class="block w-full px-3 py-2.5 border <?php echo isset($errors['phone']) ? 'border-red-500 bg-red-50 dark:bg-red-900/10' : 'border-gray-300 dark:border-gray-600'; ?> rounded-lg text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                                       placeholder="Phone number"
                                       value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">For tournament notifications</p>
                        <?php if (isset($errors['phone'])): ?>
                            <p class="mt-1 text-xs text-red-500"><?php echo htmlspecialchars($errors['phone']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Terms -->
                    <div class="space-y-2">
                        <label class="flex items-start gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="agree_terms" id="agree_terms" class="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-600 dark:text-gray-400">I agree to the <a href="#" class="text-blue-600 hover:underline">Terms of Service</a></span>
                        </label>
                        <?php if (isset($errors['agree_terms'])): ?>
                            <p class="text-xs text-red-500"><?php echo htmlspecialchars($errors['agree_terms']); ?></p>
                        <?php endif; ?>
                        <label class="flex items-start gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="agree_privacy" id="agree_privacy" class="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-600 dark:text-gray-400">I agree to the <a href="#" class="text-blue-600 hover:underline">Privacy Policy</a></span>
                        </label>
                        <?php if (isset($errors['agree_privacy'])): ?>
                            <p class="text-xs text-red-500"><?php echo htmlspecialchars($errors['agree_privacy']); ?></p>
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

                    <!-- Submit -->
                    <button type="submit" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" id="registerBtn">
                        <span class="btn-text">Create Account</span>
                        <span class="btn-loader hidden"><i class="fas fa-spinner fa-spin"></i> Creating account...</span>
                    </button>
                </div>
            </form>

            <!-- Divider -->
            <div class="flex items-center gap-3 my-5">
                <div class="flex-1 border-t border-gray-200 dark:border-gray-700"></div>
                <span class="text-xs text-gray-400">or</span>
                <div class="flex-1 border-t border-gray-200 dark:border-gray-700"></div>
            </div>

            <!-- Login link -->
            <a href="index.php?page=login" class="block w-full py-2.5 px-4 text-center text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                <i class="fas fa-sign-in-alt mr-1.5"></i>Already have an account? Sign In
            </a>
        </div>

        <p class="text-center text-xs text-gray-400 mt-4">&copy; 2026 DreamBD</p>
    </div>
</div>
