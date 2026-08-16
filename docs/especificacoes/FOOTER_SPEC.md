# FOOTER & COOKIE CONSENT SPECIFICATIONS

> **Context:** This document defines the visual and functional specifications for the "Ouvidoria Footer", including a GDPR-compliant Cookie Management System.
> **Usage:** Provide this file to an AI Assistant to allow it to perfectly recreate the footer and cookie logic in a new codebase.

---

## 1. 🦶 Visual Design System (CSS Tokens)

### Layout & Spacing
- **Container:** `<footer>` tag at the bottom of the page.
- **Background (Light):** `#f8fafc` (Neutral-50) or White, depending on page context.
- **Background (Dark):** `#0f172a` (Neutral-900).
- **Border-Top:** `1px solid var(--border-color)` (`#e2e8f0` / `#334155`).
- **Padding:** `3rem 0` (Vertical spacing).
- **Grid:** Responsive Grid (`grid-template-columns: repeat(auto-fit, minmax(250px, 1fr))`) with `gap: 2rem`.

### Typography
- **Headings:** `h4`, `font-size: 1.125rem`, `font-weight: 600`, `color: var(--text-title)`.
- **Links:** `color: var(--text-secondary)`, `text-decoration: none`. Hover: `color: var(--brand-500)`.
- **Copyright Text:** `font-size: 0.875rem`, `color: var(--text-tertiary)`, center-aligned, top-border separator.

### Elements
1.  **Brand Column:** Logo + Short Description.
2.  **Quick Links:** "Início", "Nova Manifestação", "Acompanhar", "Login".
3.  **Legal Links:** "Política de Privacidade", "Termos de Uso", "**Cookies**" (Triggers Modal).
4.  **Contact:** Address, Phone, Email with icons (`span.material-symbols-outlined`).

---

## 2. 🍪 Cookie Consent System (GDPR/LGPD)

The footer MUST include a robust cookie management system.

### A. Floating Banner (Initial State)
- **Position:** Fixed at bottom (`bottom: 0`, `left: 0`, `right: 0`).
- **Z-Index:** High (`999`).
- **Appearance:** `background: var(--bg-card)`, `border-top: 1px solid var(--border-color)`, `padding: 1rem`.
- **Content:** Text explaining cookie usage + Buttons: "Aceitar Todos", "Rejeitar", "Gerenciar Preferências".
- **Transition:** Slide up animation (`transform: translateY(100%)` -> `0`).

### B. Preferences Modal (Deep Config)
- **Trigger:** "Gerenciar Preferências" button OR "Cookies" link in footer.
- **Structure:**
    -   **Header:** Title "Preferências de Cookies".
    -   **Body:** List of toggle switches.
        1.  **Essenciais (Required):** Always checked, disabled.
        2.  **Analíticos (Optional):** Checkbox.
        3.  **Marketing (Optional):** Checkbox.
    -   **Footer:** "Salvar Preferências".

### C. JavaScript Logic (`cookie-manager.js`)
- **Storage:** `localStorage.getItem('cookie_consent')`.
- **Structure in Storage:** JSON object `{ necessary: true, analytics: bool, marketing: bool, timestamp: date }`.
- **Logic:**
    -   On Load: Check if `cookie_consent` exists. If NOT, show Banner.
    -   Radio/Checkbox state must be preserved.
    -   **Integration:** If `analytics: true`, inject Google Analytics script dynamically.

---

## 3. 🤖 AI PROMPT (Copy & Paste this to start)

> "I need to implement a professional Footer with an integrated Cookie Consent System based on the 'Ouvidoria Style'.
> 
> **Footer Specs:**
> Create a responsive 4-column footer using semantic HTML5. Include a 'Brand' column, 'Quick Links', 'Legal', and 'Contact'. Ensure the background and text colors use CSS variables (`var(--bg-card)`, `var(--text-secondary)`) for dark mode compatibility.
> 
> **Cookie System Specs:**
> 1.  **Banner:** Create a fixed bottom banner that appears if no consent is stored in localStorage.
> 2.  **Modal:** Create a hidden modal for 'Cookie Preferences' with toggles for Essential (locked), Analytics, and Marketing.
> 3.  **Logic:** Write vanilla JS to handle:
>     -   Saving preferences to localStorage.
>     -   Hiding the banner upon action.
>     -   Dynamically loading an example analytics script ONLY if 'analytics' permission is granted.
> 4.  **Trigger:** Ensure the 'Cookies' link in the footer opens the Preferences Modal for re-configuration."
