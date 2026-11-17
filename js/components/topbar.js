/**
 * Topbar Component
 * Top header bar with user profile
 */
Now.getManager('component').define('topbar', {
  template:
    `<header class="topbar">
      <div data-component="api" data-endpoint="api/index/auth/me">
        <div class="topbar-left">
          <h1 data-text="data.web_title">Nowjs</h1>
          <p data-text="data.web_description">Admin Framework</p>
        </div>
        <div class="topbar-right">
          <!-- User profile dropdown -->
          <button type="button" class="user-profile btn dropdown">
            <img data-attr="src:data.avatar" class="avatar">
            <span class="user-name" data-text="data.name"></span>
            <ul>
              <li data-show="data.id > 0"><a data-attr="href:'profile?id=' + data.id" class="icon-customer" data-i18n>Edit profile</a></li>
              <li data-show="data.id > 0"><a href="logout" class="icon-signout" data-i18n>Logout</a></li>
              <li data-show="data.id == 0"><a href="login" class="icon-signin" data-i18n>Login</a></li>
            </ul>
          </button>

          <!-- Theme toggle component (cycles configured themes) -->
          <button type="button" data-component="config" class="nav-link" title="Toggle theme"></button>

          <!-- Menu toggle component (toggles side menu) -->
          <button type="button" class="menu-toggle sidemenu-toggle">
            <span class="toggle-icon">
              <span class="toggle-bar"></span>
              <span class="toggle-bar"></span>
              <span class="toggle-bar"></span>
            </span>
          </button>
        </div>
      </div>
    </header>`,

  // Admin pages are Thai only (no language switch here). Someone who picked
  // English on the member pages still gets the admin pages in Thai.
  async mounted() {
    const i18n = Now.getManager('i18n');
    if (i18n && typeof i18n.setLocale === 'function' && i18n.getCurrentLocale?.() !== 'th') {
      try {
        await i18n.setLocale('th');
      } catch (e) {
        console.warn('topbar: could not switch to Thai', e);
      }
    }
  }
});
