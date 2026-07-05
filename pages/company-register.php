<?php
/**
 * Public company registration page.
 */

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
$base_url = rtrim(get_base_url(), '/');
$public_asset_url = static function (string $path) use ($base_url): string {
    return $base_url . '/' . ltrim(foxdesk_asset_url($path), '/');
};
$public_app_url = static function (string $path) use ($base_url): string {
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return $base_url . '/' . ltrim($path, '/');
};
$login_url = $public_app_url(url('login'));
$app_logo = get_setting('app_logo', '');
$app_logo_url = $app_logo !== '' ? $public_app_url(upload_url($app_logo)) : '';
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
                header('Location: ' . $public_app_url('index.php?page=login&registered=1'));
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
    <link href="<?php echo e($public_asset_url('tailwind.min.css')); ?>" rel="stylesheet">
    <link href="<?php echo e($public_asset_url('theme.css')); ?>" rel="stylesheet">
    <script>
        (function () {
            const saved = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
    <style>
        .company-register-shell {
            display: flex;
            min-height: 100vh;
            width: 100%;
            margin: 0;
            background: var(--surface-primary);
        }

        .company-register-brand {
            display: none;
        }

        .company-register-main {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
        }

        .company-register-card {
            width: 100%;
            max-width: 440px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 1rem;
            box-shadow:
                0 25px 50px -12px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            padding: 2rem;
        }

        .company-register-logo {
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--corp-blue-500) 0%, var(--corp-blue-600) 100%);
            color: #fff;
            box-shadow: 0 10px 40px -10px rgba(59, 130, 246, 0.5);
            overflow: hidden;
        }

        .company-register-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .company-register-form-grid {
            display: grid;
            gap: 1rem;
        }

        @media (min-width: 1024px) {
            .company-register-brand {
                display: flex;
                width: 50%;
                min-height: 100vh;
                align-items: center;
                justify-content: center;
                position: relative;
                overflow: hidden;
                background: linear-gradient(135deg, #3c50e0 0%, #1c2434 100%);
                color: white;
                border-right: 1px solid var(--border-light);
                padding: 3rem;
            }

            .company-register-brand::after {
                content: '';
                position: absolute;
                inset: 0;
                background:
                    linear-gradient(135deg, rgba(255,255,255,0.14) 0%, rgba(255,255,255,0) 38%),
                    radial-gradient(circle at 20% 20%, rgba(255,255,255,0.16), transparent 28%);
                pointer-events: none;
            }

            .company-register-brand-content {
                position: relative;
                z-index: 1;
                max-width: 30rem;
            }

            .company-register-main {
                width: 50%;
                padding: 2rem;
            }
        }
    </style>
</head>

<body class="company-register-shell font-sans text-theme-primary">
    <aside class="company-register-brand" aria-hidden="true">
        <div class="company-register-brand-content text-center">
            <?php if ($app_logo_url !== ''): ?>
                <img src="<?php echo e($app_logo_url); ?>" alt="" class="w-24 h-24 rounded-full object-cover mx-auto mb-8 shadow-2xl ring-4 ring-white/10">
            <?php else: ?>
                <div class="w-24 h-24 rounded-full flex items-center justify-center mx-auto mb-8 shadow-2xl bg-white/15 ring-4 ring-white/10">
                    <span class="text-white text-4xl font-bold"><?php echo e(strtoupper(substr($app_name, 0, 1))); ?></span>
                </div>
            <?php endif; ?>
            <h1 class="text-4xl font-bold mb-4"><?php echo e($app_name); ?></h1>
            <p class="text-slate-200 text-lg leading-relaxed">
                <?php echo e(t('Create your account to open and follow tickets with your company.')); ?>
            </p>
        </div>
    </aside>

    <main class="company-register-main">
        <section class="company-register-card animate-fade-in">
            <div class="mb-8">
                <div class="company-register-logo mb-5">
                    <?php if ($app_logo_url !== ''): ?>
                        <img src="<?php echo e($app_logo_url); ?>" alt="<?php echo e($app_name); ?>">
                    <?php else: ?>
                        <?php echo get_icon('user-plus', 'w-6 h-6'); ?>
                    <?php endif; ?>
                </div>
                <p class="text-sm font-semibold uppercase tracking-wide text-theme-muted"><?php echo e($app_name); ?></p>
                <h1 class="text-3xl font-bold mt-2 mb-2 text-theme-primary"><?php echo e(t('Create your account')); ?></h1>
                <p class="text-theme-muted leading-relaxed">
                    <?php echo e(t('Your account will be linked to')); ?> <strong class="text-theme-primary"><?php echo e($company_name); ?></strong>.
                </p>
            </div>

            <?php if (!$can_register): ?>
                <div class="alert alert-error mb-6 text-sm rounded-lg p-3 bg-red-50 text-red-600 border border-red-200 dark:bg-red-900/30 dark:border-red-800/50 dark:text-red-400">
                    <?php echo e($validation['message'] ?? t('This signup link is invalid.')); ?>
                </div>
                <a href="<?php echo e($login_url); ?>" class="btn btn-secondary w-full justify-center">
                    <?php echo e(t('Go to sign in')); ?>
                </a>
            <?php else: ?>
                <?php if ($form_error): ?>
                    <div class="alert alert-error mb-6 text-sm rounded-lg p-3 bg-red-50 text-red-600 border border-red-200 dark:bg-red-900/30 dark:border-red-800/50 dark:text-red-400">
                        <?php echo e($form_error); ?>
                        <?php if ($email_exists_error): ?>
                            <a href="<?php echo e($login_url); ?>" class="font-semibold underline ml-1"><?php echo e(t('Sign in')); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="company-register-form-grid">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Name')); ?></label>
                        <input type="text" name="name" value="<?php echo e($field_values['name']); ?>" class="form-input w-full rounded-lg px-4 py-2.5" autocomplete="name" required autofocus>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Email')); ?></label>
                        <input type="email" name="email" value="<?php echo e($field_values['email']); ?>" class="form-input w-full rounded-lg px-4 py-2.5" autocomplete="email" inputmode="email" autocapitalize="none" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Password')); ?></label>
                        <input type="password" name="password" class="form-input w-full rounded-lg px-4 py-2.5" autocomplete="new-password" minlength="8" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5"><?php echo e(t('Confirm password')); ?></label>
                        <input type="password" name="confirm_password" class="form-input w-full rounded-lg px-4 py-2.5" autocomplete="new-password" minlength="8" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-full justify-center py-2.5 text-base mt-2">
                        <?php echo get_icon('user-plus', 'w-4 h-4'); ?>
                        <?php echo e(t('Create account')); ?>
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-theme-muted">
                    <?php echo e(t('Already have an account?')); ?>
                    <a href="<?php echo e($login_url); ?>" class="font-semibold" style="color: var(--primary);">
                        <?php echo e(t('Sign in')); ?>
                    </a>
                </p>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>
