document.addEventListener('DOMContentLoaded', () => {
  const toggleButtons = document.querySelectorAll('.toggle-password');
  toggleButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const input = button.parentElement.querySelector('input');
      if (!input) return;

      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      button.textContent = isPassword ? '🙈' : '👁';
      button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
  });

  const passwordInput = document.getElementById('password');
  const strengthText = document.getElementById('passwordStrength');

  if (passwordInput && strengthText) {
    passwordInput.addEventListener('input', () => {
      const value = passwordInput.value;
      let score = 0;

      if (value.length >= 8) score++;
      if (/[A-Z]/.test(value)) score++;
      if (/[a-z]/.test(value)) score++;
      if (/[0-9]/.test(value)) score++;
      if (/[^A-Za-z0-9]/.test(value)) score++;

      if (score <= 2) strengthText.textContent = 'Password strength: Weak';
      else if (score >= 3 && score <= 4) strengthText.textContent = 'Password strength: Medium';
      else strengthText.textContent = 'Password strength: Strong';
    });
  }
});
