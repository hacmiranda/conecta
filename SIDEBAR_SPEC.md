# SIDEBAR BLUEPRINT & SPECIFICATIONS
> **Context:** This document defines the visual and functional specifications for the "Ouvidoria Sidebar".
> **Usage:** Provide this file to an AI Assistant to allow it to perfectly recreate the component in a new codebase.

---

## 1. 🎨 Visual Design System (CSS Tokens)

### Dimensions & Layout
- **Width:** `18rem` (approx 288px)
- **Height:** `100vh` (Sticky or Fixed)
- **Padding:** `1.5rem` (24px)
- **Layout:** Flex Column (`gap: 2rem`)

### Colors & Gradients
- **Background (Light Mode):** `linear-gradient(180deg, #1e3a8a 0%, #1e428a 50%, #1a3a7a 100%)` (Deep Blue Gradient)
- **Background (Dark Mode):** `linear-gradient(180deg, #020617 0%, #0f172a 100%)` (Midnight Blue)
- **Text Color:** `#ffffff` (White - Fixed for contrast against dark sidebar)
- **Text Secondary:** `#dbeafe` (Light Blue - mostly for inactive links)
- **Glass Effect (Borders/Backgrounds):** `rgba(255, 255, 255, 0.1)` (10% White opacity)

### Typography
- **Family:** 'Inter', sans-serif
- **Icons:** Material Symbols Outlined (Google Fonts)

### Shadows & Depth
- **Sidebar Shadow:** `0 25px 50px -12px rgba(0, 0, 0, 0.25)`

---

## 2. 🧩 Component Architecture

### A. Logo Section (Header)
- **Container:** Flex row, vertically centered.
- **Background:** Glassmorphism (`rgba(255,255,255,0.1)`), rounded corners (`0.75rem`).
- **Content:** Logo image (left) + Text "Ouvidoria" (right).

### B. User Avatar & Profile (The "Hero" Component)
This is the centerpiece of the sidebar.

1.  **Avatar Circle:**
    -   **Size:** `5rem` x `5rem` (80px).
    -   **Border:** `4px solid rgba(255, 255, 255, 0.2)`.
    -   **Behavior:** Scale up (`1.05`) on hover.
    -   **Content:** 
        -   If Image exists: `<img>` object-fit cover.
        -   If No Image: Gradient div (`linear-gradient(to bottom right, #60a5fa, #a855f7)`) with User Initials centered.
    -   **Interaction:** Clicking toggles the **Avatar Dropdown Menu**.

2.  **Avatar Info:**
    -   User Name (Bold, White).
    -   User Role (Smaller, `#bfdbfe`).

### C. Navigation Links
- **State Normal:** Transparent background, `#dbeafe` text. Border-left: `4px solid transparent`.
- **State Hover:** Background `rgba(255,255,255,0.1)`, Text White. Border-left: `rgba(255,255,255,0.5)`. Icon zoom (`scale(1.1)`).
- **State Active:** Background `rgba(255,255,255,0.15)`, Text White. Border-left: `4px solid #ffffff`.

---

## 3. ⚙️ Functional Specifications (Behavior)

### Avatar Dropdown Menu (Context Menu)
When the avatar is clicked, a menu must appear:
- **Position:** Absolute, below the avatar, z-index high.
- **Styling:** White background (Dark mode: `#1e293b`), rounded, shadow `lg`.
- **Validation:** Must detect clicking *outside* to close.
- **Items:**
    1.  **"Alterar Foto":** Triggers a hidden `<input type="file">`. Auto-submits on change via JS (`AvatarController`).
    2.  **"Alterar Tema":** Toggles CSS variable context (`light` <-> `dark`) and saves to localStorage.
    3.  **"Meus Dados":** Link to profile edit modal.
    4.  **"Sair":** Logout link.

### Image Upload Logic
- Hidden input: `<input type="file" id="avatar-upload" accept="image/*" style="display: none">`
- JS Listener: `change` event on input -> `FormData` -> POST to `/avatar/upload`.

---

## 4. 🤖 AI PROMPT (Copy & Paste this to start)

> "I need you to implement a Sidebar component for my web application based on the 'Ouvidoria Style'.
> 
> **Visual Specs:**
> Use a fixed 18rem width with a deep blue vertical gradient background (#1e3a8a to #1a3a7a). Use 'Inter' font and 'Material Symbols'.
> 
> **Key Features:**
> 1. **Glassmorphism:** Use semi-transparent white backgrounds (10% opacity) for the Logo container and active menu items.
> 2. **Interactive Avatar:** Center a large 5rem circular avatar. It must display user initials if no image is found. On hover, it should scale slightly. Clicking it opens a dropdown menu.
> 3. **Navigation:** Links should have a transparent left border that turns white and glows when active/hovered.
> 4. **Theme Toggle:** Include functionality in the avatar menu to switch between Light/Dark modes using CSS variables.
> 
> Please generate the HTML structure (PHP), the CSS (using the specific gradients provided), and the necessary JavaScript for the dropdown and theme toggle."
