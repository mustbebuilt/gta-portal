<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="theme117-icon">
@switch($icon)
@case('home')<path d="m3 10 9-7 9 7v10H3Z M9 20v-7h6v7"/>@break
@case('people')<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3 M16 5a3 3 0 0 1 0 6 M18 15a5 5 0 0 1 3 5"/>@break
@case('register')<rect x="5" y="5" width="14" height="16" rx="2"/><path d="M9 3h6v4H9Z m0 8 2 2 4-4 M9 17h6"/>@break
@case('support')<path d="M20 5a5 5 0 0 0-8 1 5 5 0 0 0-8-1c-4 4 0 9 8 15 8-6 12-11 8-15Z"/>@break
@case('employer')<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 5V3h8v2 M8 10h1 m6 0h1 m-8 4h1 m6 0h1 M10 21v-4h4v4"/>@break
@case('report')<path d="M4 3v18h17 M8 16v-4 m5 4V8 m5 8V5"/>@break
@case('calendar')<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4 m10-4v4 M3 11h18"/>@break
@case('review')<path d="M4 4h16v12H9l-5 5Z M8 8h8 M8 12h5"/>@break
@default<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M9 5V3h6v2 M8 11h8 M8 16h5"/>
@endswitch
</svg>
