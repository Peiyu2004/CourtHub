document.addEventListener('DOMContentLoaded', () => {

    /* =====================================================
       Universal Password Visibility Toggle
       ===================================================== */
    const toggleButtons = document.querySelectorAll('.toggle-password');

    toggleButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();

            const wrapper = button.closest('.password-input-wrapper');
            const input = wrapper ? wrapper.querySelector('input') : button.previousElementSibling;

            if (input) {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                button.textContent = isPassword ? 'Hide' : 'Show';
            }
        });
    });

    /* =====================================================
       Profile Page Tab Switcher
       ===================================================== */
    const navTabs = document.querySelectorAll('.nav-tab');
    const tabPanes = document.querySelectorAll('.tab-pane');

    if (navTabs.length > 0) {
        navTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const targetId = tab.getAttribute('data-target');

                navTabs.forEach(t => t.classList.remove('active'));
                tabPanes.forEach(pane => pane.classList.remove('active'));

                tab.classList.add('active');
                const targetPane = document.getElementById(targetId);
                if (targetPane) {
                    targetPane.classList.add('active');
                }
            });
        });
    }

    /* =====================================================
       Login Page Enhancements
       ===================================================== */
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        const submitBtn = loginForm.querySelector('.btn-auth-submit');
        const emailInput = loginForm.querySelector('#email');
        const passwordInput = loginForm.querySelector('#password');
        const errorBox = loginForm.querySelector('#loginClientErrors');

        const showLoginErrors = (messages) => {
            if (!errorBox) {
                alert(messages.join("\n"));
                return;
            }

            errorBox.innerHTML = '';
            messages.forEach(message => {
                const line = document.createElement('p');
                line.textContent = message;
                errorBox.appendChild(line);
            });
            errorBox.hidden = false;
        };

        const clearLoginErrors = () => {
            if (errorBox) {
                errorBox.hidden = true;
                errorBox.innerHTML = '';
            }
            [emailInput, passwordInput].forEach(input => {
                if (input) {
                    input.classList.remove('input-error');
                }
            });
        };

        // Clear the message as soon as the user starts fixing the field,
        // so a stale error doesn't sit under a form that now looks fine
        [emailInput, passwordInput].forEach(input => {
            if (input) {
                input.addEventListener('input', clearLoginErrors);
            }
        });

        loginForm.addEventListener('submit', (e) => {
            clearLoginErrors();

            const errors = [];
            let firstInvalid = null;

            if (!emailInput || emailInput.value.trim() === '') {
                errors.push('Please enter your email.');
                if (emailInput) {
                    emailInput.classList.add('input-error');
                    firstInvalid = firstInvalid || emailInput;
                }
            }

            if (!passwordInput || passwordInput.value === '') {
                errors.push('Please enter your password.');
                if (passwordInput) {
                    passwordInput.classList.add('input-error');
                    firstInvalid = firstInvalid || passwordInput;
                }
            }

            if (errors.length > 0) {
                e.preventDefault();
                showLoginErrors(errors);
                if (firstInvalid) {
                    firstInvalid.focus();
                }
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Logging in...';
            }
        });
    }

    /* =====================================================
       Register Page Validation
       ===================================================== */
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        const fullName = registerForm.querySelector('#full_name');
        const email = registerForm.querySelector('#email');
        const phone = registerForm.querySelector('#phone');
        const password = registerForm.querySelector('#password');
        const confirmPassword = registerForm.querySelector('#confirm_password');
        const submitBtn = registerForm.querySelector('.btn-auth-submit');

        registerForm.addEventListener('submit', (e) => {
            let errors = [];

            if (fullName && fullName.value.trim().length < 2) {
                errors.push("Full name must be at least 2 characters.");
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (email && !emailRegex.test(email.value.trim())) {
                errors.push("Please enter a valid email address.");
            }

            if (phone && phone.value.trim() !== '') {
                const phoneRegex = /^[0-9+\s\-()]{7,20}$/;
                if (!phoneRegex.test(phone.value.trim())) {
                    errors.push("Please enter a valid phone number format.");
                }
            }

            const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d).{6,}$/;
            if (password && !passwordRegex.test(password.value)) {
                errors.push("Password must be at least 6 characters long and contain both letters and numbers.");
            }

            if (password && confirmPassword && password.value !== confirmPassword.value) {
                errors.push("Passwords do not match.");
            }

            if (errors.length > 0) {
                e.preventDefault();
                alert(errors.join("\n"));
                return false;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Creating Account...';
            }
        });
    }

    /* =====================================================
       Profile Page Password Validation
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