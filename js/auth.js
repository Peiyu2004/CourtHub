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
        
        // Target registration client error box
        const registerErrorBox = registerForm.querySelector('#registerClientErrors');
        const regInputs = [fullName, email, phone, password, confirmPassword];

        // Helper: Display errors dynamically in container
        const showRegisterErrors = (messages) => {
            if (!registerErrorBox) return;
            registerErrorBox.innerHTML = '';
            messages.forEach(message => {
                const line = document.createElement('p');
                line.textContent = message;
                registerErrorBox.appendChild(line);
            });
            registerErrorBox.hidden = false;
            registerErrorBox.style.display = 'block';
        };

        // Helper: Clear input states & hide error box
        const clearRegisterErrors = () => {
            if (registerErrorBox) {
                registerErrorBox.hidden = true;
                registerErrorBox.style.display = 'none';
                registerErrorBox.innerHTML = '';
            }
            regInputs.forEach(input => {
                if (input) input.classList.remove('input-error');
            });
        };

        // Dynamic input handlers to reset error state as user types
        regInputs.forEach(input => {
            if (input) {
                input.addEventListener('input', clearRegisterErrors);
            }
        });

        registerForm.addEventListener('submit', (e) => {
            clearRegisterErrors();
            let errors = [];
            let firstInvalid = null;

            // 1. Full Name Validation (Empty check & format/length check)
            const nameVal = fullName ? fullName.value.trim() : '';
            const nameRegex = /^[a-zA-Z\s'-]+$/;
            if (fullName && nameVal === '') {
                errors.push("Full name is required.");
                fullName.classList.add('input-error');
                firstInvalid = firstInvalid || fullName;
            } else if (fullName && (nameVal.length < 2 || nameVal.length > 100)) {
                errors.push("Full name must be between 2 and 100 characters.");
                fullName.classList.add('input-error');
                firstInvalid = firstInvalid || fullName;
            } else if (fullName && !nameRegex.test(nameVal)) {
                errors.push("Full name can only contain letters, spaces, hyphens, and apostrophes.");
                fullName.classList.add('input-error');
                firstInvalid = firstInvalid || fullName;
            }

            // 2. Email Validation (Empty check & valid email format check)
            const emailVal = email ? email.value.trim() : '';
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (email && emailVal === '') {
                errors.push("Email address is required.");
                email.classList.add('input-error');
                firstInvalid = firstInvalid || email;
            } else if (email && !emailRegex.test(emailVal)) {
                errors.push("Please enter a valid email address.");
                email.classList.add('input-error');
                firstInvalid = firstInvalid || email;
            }

            // 3. Phone Validation (Empty check & phone format check)
            const phoneVal = phone ? phone.value.trim() : '';
            const phoneRegex = /^[0-9+\s\-()]{7,20}$/;
            if (phone && phoneVal === '') {
                errors.push("Phone number is required.");
                phone.classList.add('input-error');
                firstInvalid = firstInvalid || phone;
            } else if (phone && !phoneRegex.test(phoneVal)) {
                errors.push("Please enter a valid phone number (e.g., 0123456789).");
                phone.classList.add('input-error');
                firstInvalid = firstInvalid || phone;
            }

            // 4. Password Validation (Empty check & length/complexity check)
            const passwordVal = password ? password.value : '';
            const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d).{6,}$/;
            if (password && passwordVal === '') {
                errors.push("Password is required.");
                password.classList.add('input-error');
                firstInvalid = firstInvalid || password;
            } else if (password && !passwordRegex.test(passwordVal)) {
                errors.push("Password must be at least 6 characters long and contain both letters and numbers.");
                password.classList.add('input-error');
                firstInvalid = firstInvalid || password;
            }

            // 5. Confirm Password Validation (Empty check & matching check)
            const confirmVal = confirmPassword ? confirmPassword.value : '';
            if (confirmPassword && confirmVal === '') {
                errors.push("Confirm password is required.");
                confirmPassword.classList.add('input-error');
                firstInvalid = firstInvalid || confirmPassword;
            } else if (password && confirmPassword && passwordVal !== confirmVal) {
                errors.push("Passwords do not match.");
                confirmPassword.classList.add('input-error');
                firstInvalid = firstInvalid || confirmPassword;
            }

            // Render errors to error box instead of alert window
            if (errors.length > 0) {
                e.preventDefault();
                showRegisterErrors(errors);
                if (firstInvalid) {
                    firstInvalid.focus();
                }
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Creating Account...';
            }
        });
    }

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

    /* Helper: Show Client-Side Alert Box & Hide PHP Server Alerts */
    const showAlertBox = (boxElement, messages, type = 'error') => {
        if (!boxElement) return;
        
        const activeTab = boxElement.closest('.tab-pane') || document;
        const serverAlerts = activeTab.querySelectorAll('.server-alert');
        serverAlerts.forEach(el => el.style.display = 'none');

        boxElement.className = `alert alert-${type}`;
        boxElement.innerHTML = messages.map(msg => `<p>${msg}</p>`).join('');
        boxElement.style.display = 'block';
    };

    /* Helper: Hide Client-Side Alert Box */
    const hideAlertBox = (boxElement) => {
        if (boxElement) {
            boxElement.style.display = 'none';
            boxElement.innerHTML = '';
        }
    };

    /* Helper: Hide PHP Server-rendered Alerts */
    const hideServerAlerts = (container) => {
        const serverAlerts = container.querySelectorAll('.server-alert');
        serverAlerts.forEach(el => el.style.display = 'none');
    };

    /* =====================================================
       Profile Info Form Validation & No-Changes Handler
       ===================================================== */
    const profileInfoForm = document.getElementById('profileInfoForm');
    if (profileInfoForm) {
        const fullNameInput = profileInfoForm.querySelector('#full_name');
        const emailInput = profileInfoForm.querySelector('#email');
        const phoneInput = profileInfoForm.querySelector('#phone');
        const alertBox = document.getElementById('profileClientAlert');
        const inputs = [fullNameInput, emailInput, phoneInput];

        inputs.forEach(input => {
            if (input) {
                input.addEventListener('input', () => {
                    hideAlertBox(alertBox);
                    hideServerAlerts(profileInfoForm.parentElement);
                    input.classList.remove('input-error');
                });
            }
        });

        profileInfoForm.addEventListener('submit', (e) => {
            hideAlertBox(alertBox);
            hideServerAlerts(profileInfoForm.parentElement);
            inputs.forEach(input => input && input.classList.remove('input-error'));

            const errors = [];
            let firstInvalid = null;

            if (fullNameInput && fullNameInput.value.trim().length < 2) {
                errors.push("Full name must be at least 2 characters long.");
                fullNameInput.classList.add('input-error');
                firstInvalid = firstInvalid || fullNameInput;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (emailInput && !emailRegex.test(emailInput.value.trim())) {
                errors.push("Please enter a valid email address.");
                emailInput.classList.add('input-error');
                firstInvalid = firstInvalid || emailInput;
            }

            const phoneVal = phoneInput ? phoneInput.value.trim() : '';
            const phoneRegex = /^[0-9+\s\-()]{7,20}$/;
            if (phoneVal === '') {
                errors.push("Please enter your phone number.");
                if (phoneInput) phoneInput.classList.add('input-error');
                firstInvalid = firstInvalid || phoneInput;
            } else if (!phoneRegex.test(phoneVal)) {
                errors.push("Please enter a valid phone number format.");
                if (phoneInput) phoneInput.classList.add('input-error');
                firstInvalid = firstInvalid || phoneInput;
            }

            if (errors.length > 0) {
                e.preventDefault();
                showAlertBox(alertBox, errors, 'error');
                if (firstInvalid) firstInvalid.focus();
                return;
            }

            const nameUnchanged = fullNameInput ? fullNameInput.value.trim() === (fullNameInput.dataset.original || '').trim() : true;
            const emailUnchanged = emailInput ? emailInput.value.trim() === (emailInput.dataset.original || '').trim() : true;
            const phoneUnchanged = phoneInput ? phoneInput.value.trim() === (phoneInput.dataset.original || '').trim() : true;

            if (nameUnchanged && emailUnchanged && phoneUnchanged) {
                e.preventDefault();
                showAlertBox(alertBox, ["No changes were made to your profile."], 'info');
            }
        });
    }

    /* =====================================================
       Profile Password Form Validation
       ===================================================== */
    const passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        const currentPassword = passwordForm.querySelector('#current_password');
        const newPassword = passwordForm.querySelector('#new_password');
        const confirmPassword = passwordForm.querySelector('#confirm_password');
        const alertBox = document.getElementById('passwordClientAlert');
        const inputs = [currentPassword, newPassword, confirmPassword];

        inputs.forEach(input => {
            if (input) {
                input.addEventListener('input', () => {
                    hideAlertBox(alertBox);
                    hideServerAlerts(passwordForm.parentElement);
                    input.classList.remove('input-error');
                });
            }
        });

        passwordForm.addEventListener('submit', (e) => {
            hideAlertBox(alertBox);
            hideServerAlerts(passwordForm.parentElement);
            inputs.forEach(input => input && input.classList.remove('input-error'));

            const errors = [];
            let firstInvalid = null;

            if (currentPassword && currentPassword.value === '') {
                errors.push("Please enter your current password.");
                currentPassword.classList.add('input-error');
                firstInvalid = firstInvalid || currentPassword;
            }

            if (newPassword && newPassword.value === '') {
                errors.push("Please enter your new password.");
                newPassword.classList.add('input-error');
                firstInvalid = firstInvalid || newPassword;
            } else if (newPassword && newPassword.value.length < 6) {
                errors.push("New password must be at least 6 characters long.");
                newPassword.classList.add('input-error');
                firstInvalid = firstInvalid || newPassword;
            }

            if (confirmPassword && confirmPassword.value === '') {
                errors.push("Please confirm your new password.");
                confirmPassword.classList.add('input-error');
                firstInvalid = firstInvalid || confirmPassword;
            } else if (newPassword && confirmPassword && newPassword.value !== confirmPassword.value) {
                errors.push("New passwords do not match.");
                confirmPassword.classList.add('input-error');
                firstInvalid = firstInvalid || confirmPassword;
            }

            if (errors.length > 0) {
                e.preventDefault();
                showAlertBox(alertBox, errors, 'error');
                if (firstInvalid) firstInvalid.focus();
            }
        });
    }

    /* =====================================================
       Delete Account Tab Form Validation
       ===================================================== */
    const deleteAccountForm = document.getElementById('deleteAccountForm');
    if (deleteAccountForm) {
        const deletePasswordInput = deleteAccountForm.querySelector('#delete_password');
        const deleteAlertBox = document.getElementById('deleteClientAlert');

        if (deletePasswordInput) {
            deletePasswordInput.addEventListener('input', () => {
                hideAlertBox(deleteAlertBox);
                hideServerAlerts(deleteAccountForm.parentElement);
                deletePasswordInput.classList.remove('input-error');
            });
        }

        deleteAccountForm.addEventListener('submit', (e) => {
            hideAlertBox(deleteAlertBox);
            hideServerAlerts(deleteAccountForm.parentElement);

            if (!deletePasswordInput || deletePasswordInput.value.trim() === '') {
                e.preventDefault();
                deletePasswordInput.classList.add('input-error');
                showAlertBox(deleteAlertBox, ["Please enter your password to confirm account deletion."], 'error');
                deletePasswordInput.focus();
            }
        });
    }
});