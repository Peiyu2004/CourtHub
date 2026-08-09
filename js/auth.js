document.addEventListener('DOMContentLoaded', () => {

    /* =====================================================
       1. Login Page Enhancements
       ===================================================== */
    const loginForm = document.querySelector('.auth-form[action*="login.php"]');
    if (loginForm) {
        const submitBtn = loginForm.querySelector('.btn-auth-submit');

        loginForm.addEventListener('submit', () => {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Logging in...';
            }
        });
    }

    /* =====================================================
       2. Register Page Validation
       ===================================================== */
    const registerForm = document.querySelector('.auth-form[action*="register.php"]');
    if (registerForm) {
        const password = registerForm.querySelector('#password');
        const confirmPassword = registerForm.querySelector('#confirm_password');
        const submitBtn = registerForm.querySelector('.btn-auth-submit');

        registerForm.addEventListener('submit', (e) => {
            if (password && confirmPassword && password.value !== confirmPassword.value) {
                e.preventDefault();
                alert('Passwords do not match. Please re-enter.');
                confirmPassword.focus();
                return false;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Creating Account...';
            }
        });
    }

    /* =====================================================
       3. Profile Page Password Validation
       ===================================================== */
    const passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        const newPassword = passwordForm.querySelector('#new_password');
        const confirmPassword = passwordForm.querySelector('#confirm_password');

        passwordForm.addEventListener('submit', (e) => {
            if (newPassword && confirmPassword && newPassword.value !== confirmPassword.value) {
                e.preventDefault();
                alert('New passwords do not match.');
                confirmPassword.focus();
            }
        });
    }
});