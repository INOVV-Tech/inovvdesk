<?php
/**
 * Public company registration page.
 */

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$validation = company_signup_validate_link_token($token);
$link = $validation['link'] ?? null;
$company_name = (string) ($link['organization_name'] ?? t('Company'));
$form_error = '';
$email_exists_error = false;
$field_values = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $form_error = t('Security check failed. Please try again.');
    } elseif (!$validation['ok']) {
        $form_error = (string) ($validation['message'] ?? t('This signup link is invalid.'));
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm_password = (string) ($_POST['confirm_password'] ?? '');

        if ($name === '' || $email === '' || $password === '' || $confirm_password === '') {
            $form_error = t('Please fill in all required fields.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $form_error = t('Enter a valid email address.');
        } elseif (strlen($password) < 8) {
            $form_error = t('Password must be at least 8 characters.');
        } elseif ($password !== $confirm_password) {
            $form_error = t('Passwords do not match.');
        } else {
            $result = company_signup_register_client($token, $name, $email, $password);
            if (!empty($result['ok'])) {
                header('Location: index.php?page=login&registered=1');
                exit;
            }

            $form_error = (string) ($result['message'] ?? t('Could not create your account. Please try again.'));
            $email_exists_error = (($result['code'] ?? '') === 'email_exists');
        }
    }
}

$page_title = t('Create account');
$can_register = !empty($validation['ok']);
?>
<!DOCTYPE html>
<html lang="<?php echo e(get_app_language()); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> - <?php echo e($app_name); ?></title>
    <link href="<?php echo e(foxdesk_asset_url('tailwind.min.css')); ?>" rel="stylesheet">
    <link href="<?php echo e(foxdesk_asset_url('theme.css')); ?>" rel="stylesheet">
    <script>
        (function () {
            const saved = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
    <style>
        .company-register-bg {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, var(--corp-slate-100) 0%, var(--corp-slate-50) 52%, #eef4ff 100%);
        }

        [data-theme="dark"] .company-register-bg {
            background: linear-gradient(135deg, var(--corp-slate-950) 0%, var(--corp-slate-900) 52%, #0b1828 100%);
        }

        .company-register-card {
            background: var(--glass-bg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(14px);
        }
    </style>
</head>

<body class="min-h-screen font-sans text-theme-primary">
    <div class="company-register-bg"></div>
    <main class="relative z-10 min-h-screen flex items-center justify-center px-4 py-10">
        <section class="company-register-card w-full max-w-md rounded-2xl p-8">
            <div class="mb-8">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-5" style="background: var(--primary); color: white;">
                    <?php echo get_icon('user-plus', 'w-6 h-6'); ?>
                </div>
                <p class="text-sm font-semibold uppercase tracking-wide text-theme-muted"><?php echo e($app_name); ?></p>
                <h1 class="text-3xl font-bold mt-2 mb-2"><?php echo e(t('Create your account')); ?></h1>
                <p class="text-theme-muted">
                    <?php echo e(t('Your account will be linked to')); ?> <?php echo e($company_name); ?>.
                </p>
            </div>

            <?php if (!$can_register): ?>
                <div class="alert alert-error mb-6 text-sm rounded-lg p-3 bg-red-50 text-red-600 border border-red-200 dark:bg-red-900/30 dark:border-red-800/50 dark:text-red-400">
                    <?php echo e($validation['message'] ?? t('This signup link is invalid.')); ?>
                </div>
                <a href="<?php echo url('login'); ?>" class="btn btn-secondary w-full justify-center">
                    <?php echo e(t('Go to sign in')); ?>
                </a>
            <?php else: ?>
                <?php if ($form_error): ?>
                    <div class="alert alert-error mb-6 text-sm rounded-lg p-3 bg-red-50 text-red-600 border border-red-200 dark:bg-red-900/30 dark:border-red-800/50 dark:text-red-400">
                        <?php echo e($form_error); ?>
                        <?php if ($email_exists_error): ?>
                            <a href="<?php echo url('login'); ?>" class="font-semibold underline ml-1"><?php echo e(t('Sign in')); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="space-y-5">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Name')); ?></label>
                        <input
                            type="text"
                            name="name"
                            value="<?php echo e($field_values['name']); ?>"
                            class="form-input w-full rounded-lg px-4 py-2.5"
                            autocomplete="name"
                            required
                            autofocus
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Email')); ?></label>
                        <input
                            type="email"
                            name="email"
                            value="<?php echo e($field_values['email']); ?>"
                            class="form-input w-full rounded-lg px-4 py-2.5"
                            autocomplete="email"
                            inputmode="email"
                            autocapitalize="none"
                            required
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Password')); ?></label>
                        <input
                            type="password"
                            name="password"
                            class="form-input w-full rounded-lg px-4 py-2.5"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Confirm password')); ?></label>
                        <input
                            type="password"
                            name="confirm_password"
                            class="form-input w-full rounded-lg px-4 py-2.5"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary w-full justify-center py-2.5 text-base">
                        <?php echo get_icon('user-plus', 'w-4 h-4'); ?>
                        <?php echo e(t('Create account')); ?>
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-theme-muted">
                    <?php echo e(t('Already have an account?')); ?>
                    <a href="<?php echo url('login'); ?>" class="font-semibold" style="color: var(--primary);">
                        <?php echo e(t('Sign in')); ?>
                    </a>
                </p>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>
