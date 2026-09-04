<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes"/>
<title>Tin_Ustad | Bioinformatics — BAU Classroom Ecosystem</title>
<meta name="description" content="The team behind BAU Classroom Management System — Bioinformatics engineers building smart campus solutions."/>
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
  --green-50:  #edfaf4;
  --green-100: #d0f4e4;
  --green-200: #a3e8cd;
  --green-300: #5dd3a8;
  --green-400: #34b880;
  --green-500: #1e9e68;
  --green-600: #0f7a50;
  --green-700: #085c3a;
  --green-800: #053d27;
  --blue-50:  #eef5fd;
  --blue-400: #3e87d4;
  --blue-600: #1255a0;
  --blue-700: #0c3f7f;
  --blue-800: #082a5a;
  --slate-50:  #f8fafc;
  --slate-100: #f1f5f9;
  --slate-200: #e2e8f0;
  --slate-300: #cbd5e1;
  --slate-400: #94a3b8;
  --slate-500: #64748b;
  --slate-600: #475569;
  --slate-700: #334155;
  --slate-800: #1e293b;
  --slate-900: #0f172a;
  --font-display: Georgia, 'Times New Roman', serif;
  --font-body: 'Segoe UI', system-ui, -apple-system, sans-serif;
  --mono: 'SF Mono', 'Cascadia Code', 'Courier New', monospace;
  --serif: Georgia, 'Times New Roman', serif;
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 16px;
  --radius-xl: 22px;
  --radius-pill: 999px;
  --shadow-xs: 0 1px 2px rgba(15,23,42,0.06);
  --shadow-sm: 0 2px 6px rgba(15,23,42,0.07), 0 1px 2px rgba(15,23,42,0.05);
  --shadow-md: 0 6px 20px rgba(15,23,42,0.09), 0 2px 6px rgba(15,23,42,0.05);
}

html { scroll-behavior: smooth; }
body {
  font-family: var(--font-body);
  background: var(--slate-100);
  color: var(--slate-800);
  line-height: 1.6;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  overflow-x: hidden;
}

/* ===== HEADER ===== */
.header {
  background: linear-gradient(105deg, #053d27 0%, #064e3b 25%, #0c3f7f 72%, #082a5a 100%);
  padding: 0 clamp(1rem, 4vw, 3.5rem);
  min-height: 64px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 4px 20px rgba(6,30,60,0.38);
  flex-wrap: wrap;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  flex-shrink: 1;
}
.logo-img-wrap {
  width: clamp(36px, 6vw, 50px);
  height: clamp(36px, 6vw, 50px);
  border-radius: var(--radius-md);
  background: #fff;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  padding: 4px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.22), 0 0 0 1.5px rgba(255,255,255,0.18);
}
.logo-img-wrap img { width:100%; height:100%; object-fit:contain; display:block; }
.brand-text { min-width: 0; line-height: 1.25; }
.brand-text .name {
  font-size: clamp(0.72rem, 2.2vw, 1rem);
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-shadow: 0 1px 4px rgba(0,0,0,0.2);
}
.brand-text .sub {
  font-size: clamp(0.6rem, 1.5vw, 0.72rem);
  color: rgba(255,255,255,0.55);
  font-weight: 400;
  letter-spacing: 0.02em;
  white-space: normal;
  word-break: break-word;
}

.header-sep {
  width: 1px; height: 32px;
  background: rgba(255,255,255,0.15);
  flex-shrink: 0;
}

/* Hamburger */
.hamburger {
  display: none;
  flex-direction: column;
  justify-content: center;
  gap: 5px;
  background: none;
  border: 1px solid rgba(255,255,255,0.3);
  border-radius: var(--radius-sm);
  padding: 6px 8px;
  cursor: pointer;
  flex-shrink: 0;
}
.hamburger span {
  display: block;
  width: 18px; height: 2px;
  background: #fff;
  border-radius: 2px;
  transition: all 0.25s;
}
.hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

.nav-links {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
  justify-content: center;
}
.nav-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.2);
  color: rgba(255,255,255,0.85);
  text-decoration: none;
  font-size: clamp(0.65rem, 1.5vw, 0.75rem);
  font-weight: 500;
  padding: 6px 12px;
  border-radius: var(--radius-pill);
  transition: all 0.2s;
  white-space: nowrap;
  font-family: var(--mono);
}
.nav-btn:hover {
  background: rgba(255,255,255,0.18);
  border-color: rgba(255,255,255,0.45);
  color: #fff;
}

.signin-btn {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,0.11);
  border: 1.5px solid rgba(255,255,255,0.28);
  color: #fff; text-decoration: none;
  font-size: clamp(0.72rem, 1.8vw, 0.86rem); font-weight: 600;
  padding: 7px 16px; border-radius: var(--radius-pill);
  cursor: pointer;
  transition: background 0.18s, border-color 0.18s, transform 0.1s;
  white-space: nowrap; flex-shrink: 0;
}
.signin-btn:hover { background:rgba(255,255,255,0.2); border-color:rgba(255,255,255,0.5); transform:translateY(-1px); }
.signin-btn svg { width:14px; height:14px; flex-shrink:0; }
.nav-portal-btn { display: none; }

/* ===== HERO ===== */
.hero {
  min-height: clamp(55vh, 75vh, 80vh);
  background: linear-gradient(135deg, rgba(5,61,39,0.85) 0%, rgba(8,42,90,0.88) 100%),
              url('images/developers/hero/hero-background.jpg');
  background-size: cover;
  background-position: 50% 30%;
  background-repeat: no-repeat;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
}
.hero-content {
  max-width: 900px;
  padding: clamp(2rem, 8vw, 4rem) clamp(1rem, 5vw, 2rem);
  margin: 0 auto;
}
.hero-title {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: clamp(2rem, 8vw, 5rem);
  line-height: 1.0;
  letter-spacing: -0.02em;
  margin-bottom: 1.2rem;
  color: white;
  text-shadow: 0 2px 12px rgba(0,0,0,0.25);
}
.hero-title em {
  font-style: italic;
  font-family: var(--serif);
  font-weight: 250;
  color: var(--green-200);
}
.hero-desc {
  font-family: var(--mono);
  font-size: clamp(0.75rem, 2vw, 0.85rem);
  line-height: 1.8;
  color: rgba(255,255,255,0.85);
  max-width: 600px;
  margin: 0 auto;
}

/* ===== ABOUT SECTION ===== */
#about {
  display: grid;
  grid-template-columns: 1fr 1fr;
}
.about-left {
  padding: clamp(2.5rem, 6vw, 6rem) clamp(1.25rem, 4vw, 3rem);
  border-right: 1px solid var(--slate-200);
}
.about-heading {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: clamp(1.8rem, 4vw, 3.5rem);
  line-height: 1;
  letter-spacing: -0.02em;
  margin-bottom: 1.5rem;
  color: var(--slate-800);
}
.about-body {
  font-family: var(--mono);
  font-size: clamp(0.76rem, 1.5vw, 0.84rem);
  line-height: 1.9;
  color: var(--slate-600);
  max-width: 480px;
}
.about-body p+p { margin-top: 1.2rem; }
.about-right {
  padding: clamp(2.5rem, 6vw, 6rem) clamp(1.25rem, 4vw, 3rem);
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
}
.hero-stats {
  width: 100%;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1px;
  background: var(--slate-200);
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.stat {
  padding: clamp(1rem, 2.5vw, 2rem);
  background: #fff;
}
.stat-num {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: clamp(1.8rem, 4vw, 2.8rem);
  line-height: 1;
  letter-spacing: -0.02em;
  margin-bottom: 0.35rem;
  color: var(--green-700);
}
.stat-label {
  font-family: var(--mono);
  font-size: clamp(0.6rem, 1.2vw, 0.72rem);
  letter-spacing: 0.06em;
  color: var(--slate-500);
  text-transform: uppercase;
}
.about-buttons {
  margin-top: 1.8rem;
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.btn-dark {
  display: inline-flex; align-items: center; gap: 0.5rem;
  padding: clamp(0.65rem, 2vw, 0.85rem) clamp(1.2rem, 3vw, 1.8rem);
  background: var(--slate-800); color: var(--slate-50);
  font-family: var(--mono);
  font-size: clamp(0.7rem, 1.5vw, 0.78rem);
  letter-spacing: 0.04em; text-decoration: none;
  border: 1px solid var(--slate-800); border-radius: var(--radius-pill);
  transition: all 0.2s; white-space: nowrap;
}
.btn-dark:hover { background: var(--green-600); border-color: var(--green-600); }
.btn-ghost {
  display: inline-flex; align-items: center; gap: 0.5rem;
  padding: clamp(0.65rem, 2vw, 0.85rem) clamp(1.2rem, 3vw, 1.8rem);
  background: transparent; color: var(--slate-700);
  font-family: var(--mono);
  font-size: clamp(0.7rem, 1.5vw, 0.78rem);
  letter-spacing: 0.04em; text-decoration: none;
  border: 1px solid var(--slate-300); border-radius: var(--radius-pill);
  transition: all 0.2s; white-space: nowrap;
}
.btn-ghost:hover { border-color: var(--slate-600); color: var(--slate-800); }

/* ===== TEAM SECTION ===== */
#team {
  padding: clamp(3rem, 6vw, 6rem) 0;
  background: var(--slate-50);
}
.team-header {
  padding: 0 clamp(1.25rem, 4vw, 3rem);
  margin-bottom: 2rem;
}
.team-heading {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: clamp(1.8rem, 4vw, 3.5rem);
  line-height: 1;
  letter-spacing: -0.02em;
  color: var(--slate-800);
}
.team-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: clamp(1rem, 2vw, 2rem);
  padding: 0 clamp(1.25rem, 4vw, 3rem);
}
.member-card {
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  transition: all 0.25s ease;
  overflow: hidden;
  background: #fff;
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
}
.member-card:hover {
  transform: translateY(-6px);
  box-shadow: var(--shadow-md);
  border-color: var(--green-200);
}
.member-photo-wrap {
  position: relative;
  overflow: hidden;
  height: clamp(180px, 22vw, 280px);
  border-bottom: 1px solid var(--slate-200);
  background: var(--slate-100);
  flex-shrink: 0;
}
.member-photo-wrap img {
  width: 100%; height: 100%;
  object-fit: cover; object-position: top center;
  filter: grayscale(15%);
  transition: transform 0.5s ease, filter 0.3s;
}
.member-card:hover .member-photo-wrap img { transform: scale(1.04); filter: grayscale(0%); }
.member-body {
  padding: clamp(1rem, 2vw, 1.5rem);
  flex: 1; display: flex; flex-direction: column;
}
.member-name {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: clamp(0.95rem, 1.8vw, 1.25rem);
  letter-spacing: -0.01em;
  margin-bottom: 0.2rem;
  color: var(--slate-800);
}
.member-role {
  font-family: var(--mono);
  font-size: clamp(0.62rem, 1.1vw, 0.7rem);
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--green-600);
  margin-bottom: 0.7rem;
}
.member-bio {
  font-family: var(--mono);
  font-size: clamp(0.7rem, 1.2vw, 0.78rem);
  line-height: 1.65;
  color: var(--slate-600);
  margin-bottom: 0.9rem;
  flex: 1;
}
.tags {
  display: flex; flex-wrap: wrap; gap: 0.4rem;
  margin-bottom: 1rem;
}
.tag {
  font-family: var(--mono);
  font-size: clamp(0.58rem, 1vw, 0.65rem);
  letter-spacing: 0.04em;
  padding: 0.18rem 0.55rem;
  background: var(--slate-100);
  border: 1px solid var(--slate-200);
  color: var(--slate-600);
  border-radius: var(--radius-pill);
}
.member-links { display: flex; gap: 0.6rem; }
.mlink {
  width: 30px; height: 30px;
  border: 1px solid var(--slate-200);
  display: flex; align-items: center; justify-content: center;
  color: var(--slate-500); text-decoration: none;
  border-radius: var(--radius-sm);
  transition: all 0.2s;
}
.mlink:hover { border-color: var(--green-500); color: #fff; background: var(--green-500); }

/* ===== PROJECTS SECTION ===== */
#projects {
  padding: clamp(3rem, 6vw, 6rem) clamp(1.25rem, 4vw, 3rem);
  background: #fff;
}
.projects-header { margin-bottom: 2rem; }
.projects-heading {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: clamp(1.8rem, 4vw, 3.5rem);
  line-height: 1;
  letter-spacing: -0.02em;
  color: var(--slate-800);
}
.projects-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr));
  gap: clamp(1rem, 2vw, 2rem);
}
.project-card {
  background: var(--slate-50);
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}
.project-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: var(--green-200); }
.project-img { height: clamp(140px, 15vw, 180px); overflow: hidden; background: var(--slate-100); }
.project-img img { width: 100%; height: 100%; object-fit: cover; object-position: top center; transition: transform 0.3s; }
.project-card:hover .project-img img { transform: scale(1.03); }
.project-info { padding: clamp(0.9rem, 2vw, 1.2rem) clamp(0.9rem, 2vw, 1.2rem) clamp(1rem, 2.5vw, 1.5rem); }
.project-info h4 {
  font-family: var(--font-display); font-weight: 700;
  font-size: clamp(0.9rem, 1.8vw, 1.05rem);
  margin-bottom: 0.35rem; color: var(--slate-800);
}
.project-info p {
  font-family: var(--mono);
  font-size: clamp(0.68rem, 1.2vw, 0.74rem);
  line-height: 1.5; color: var(--slate-500);
}

/* ===== CULTURE SECTION ===== */
#culture {
  display: grid;
  grid-template-columns: 1fr 2fr;
}
.culture-left {
  padding: clamp(2.5rem, 6vw, 6rem) clamp(1.25rem, 4vw, 3rem);
  border-right: 1px solid var(--slate-200);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 2rem;
  background: var(--slate-50);
}
.culture-heading {
  font-family: var(--font-display); font-weight: 800;
  font-size: clamp(1.8rem, 3.5vw, 3rem);
  line-height: 1; letter-spacing: -0.02em;
  color: var(--slate-800);
}
.culture-quote {
  font-family: var(--serif);
  font-style: italic;
  font-size: clamp(0.85rem, 1.6vw, 1rem);
  line-height: 1.8;
  color: var(--slate-600);
  border-left: 2px solid var(--green-500);
  padding-left: 1.2rem;
}
.culture-right {
  padding: clamp(2.5rem, 6vw, 6rem) clamp(1.25rem, 4vw, 3rem);
  background: var(--slate-50);
}
.culture-list {
  display: flex; flex-direction: column;
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.culture-item {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: clamp(1rem, 2vw, 2rem);
  align-items: start;
  padding: clamp(1.2rem, 2.5vw, 2rem);
  border-bottom: 1px solid var(--slate-200);
  transition: background 0.15s;
  background: #fff;
}
.culture-item:last-child { border-bottom: none; }
.culture-item:hover { background: var(--slate-50); }
.culture-num {
  font-family: var(--mono);
  font-size: clamp(0.65rem, 1.2vw, 0.72rem);
  letter-spacing: 0.08em; color: var(--green-600); font-weight: 600;
  padding-top: 0.15rem;
}
.culture-title {
  font-family: var(--font-display); font-weight: 700;
  font-size: clamp(0.88rem, 1.6vw, 1rem);
  margin-bottom: 0.3rem; color: var(--slate-700);
}
.culture-desc {
  font-family: var(--mono);
  font-size: clamp(0.7rem, 1.2vw, 0.78rem);
  line-height: 1.75; color: var(--slate-500);
}

/* ===== CONTACT SECTION ===== */
#contact {
  background: linear-gradient(135deg, var(--slate-800) 0%, var(--green-800) 100%);
  color: #fff;
  padding: clamp(3rem, 6vw, 6rem) clamp(1.25rem, 4vw, 3rem);
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: clamp(2rem, 4vw, 4rem);
  align-items: center;
}
.cta-label {
  font-family: var(--mono);
  font-size: clamp(0.62rem, 1.2vw, 0.72rem);
  letter-spacing: 0.1em; text-transform: uppercase;
  color: rgba(255,255,255,0.5);
  margin-bottom: 1.2rem;
  display: flex; align-items: center; gap: 0.6rem;
}
.cta-label::before { content: ''; width: 20px; height: 1px; background: var(--green-400); }
.cta-heading {
  font-family: var(--font-display); font-weight: 800;
  font-size: clamp(2rem, 5vw, 4rem);
  line-height: 0.95; letter-spacing: -0.02em;
}
.cta-heading em { font-style: italic; font-family: var(--serif); font-weight: 400; color: var(--green-300); }
.cta-right { display: flex; flex-direction: column; gap: 1.5rem; }
.cta-text {
  font-family: var(--mono);
  font-size: clamp(0.76rem, 1.5vw, 0.84rem);
  line-height: 1.9; color: rgba(255,255,255,0.7);
}
.cta-btns { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.btn-light {
  display: inline-flex; align-items: center; gap: 0.5rem;
  padding: clamp(0.65rem, 2vw, 0.85rem) clamp(1.2rem, 3vw, 1.8rem);
  background: #fff; color: var(--green-700);
  font-family: var(--mono);
  font-size: clamp(0.7rem, 1.5vw, 0.78rem);
  letter-spacing: 0.04em; text-decoration: none;
  border: 1px solid #fff; border-radius: var(--radius-pill);
  transition: all 0.2s; white-space: nowrap;
}
.btn-light:hover { background: var(--green-400); border-color: var(--green-400); color: #fff; }
.btn-ghost-light {
  display: inline-flex; align-items: center; gap: 0.5rem;
  padding: clamp(0.65rem, 2vw, 0.85rem) clamp(1.2rem, 3vw, 1.8rem);
  background: transparent; color: rgba(255,255,255,0.7);
  font-family: var(--mono);
  font-size: clamp(0.7rem, 1.5vw, 0.78rem);
  letter-spacing: 0.04em; text-decoration: none;
  border: 1px solid rgba(255,255,255,0.3); border-radius: var(--radius-pill);
  transition: all 0.2s; white-space: nowrap;
}
.btn-ghost-light:hover { border-color: #fff; color: #fff; }

/* ===== FOOTER ===== */
.footer {
  background: var(--slate-900);
  color: rgba(255,255,255,0.42);
  padding: 1.4rem clamp(1rem, 4vw, 3.5rem);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
}
.footer-left .fl1 {
  font-size: clamp(0.7rem, 1.5vw, 0.82rem);
  color: rgba(255,255,255,0.72); font-weight: 600; margin-bottom: 3px;
}
.footer-left .fl2 { font-size: clamp(0.62rem, 1.2vw, 0.7rem); }
.footer-links { display: flex; gap: clamp(0.8rem, 2vw, 1.4rem); flex-wrap: wrap; }
.footer-links a {
  font-size: clamp(0.62rem, 1.2vw, 0.73rem);
  color: rgba(255,255,255,0.36); text-decoration: none; transition: color 0.15s;
}
.footer-links a:hover { color: rgba(255,255,255,0.75); }

/* ===== ANIMATIONS ===== */
.reveal { opacity: 0; transform: translateY(24px); transition: opacity 0.7s ease, transform 0.7s ease; }
.reveal.visible { opacity: 1; transform: translateY(0); }

/* ===== RESPONSIVE BREAKPOINTS ===== */

/* Tablet landscape / small desktop */
@media (max-width: 1024px) {
  .team-grid { grid-template-columns: repeat(3, 1fr); }
  #culture { grid-template-columns: 1fr 1.5fr; }
}

/* Tablet portrait */
@media (max-width: 900px) {
  .header-sep { display: none; }
  #about { grid-template-columns: 1fr; }
  .about-left { border-right: none; border-bottom: 1px solid var(--slate-200); }
  .about-body { max-width: 100%; }
  #culture { grid-template-columns: 1fr; }
  .culture-left { border-right: none; border-bottom: 1px solid var(--slate-200); }
  #contact { grid-template-columns: 1fr; }
  .team-grid { grid-template-columns: repeat(2, 1fr); }
}

/* Mobile landscape + small tablet */
@media (max-width: 720px) {
  .hamburger { display: flex; }
  .header-sep { display: none; }
  .nav-links {
    display: none;
    position: absolute;
    top: 100%;
    left: 0; right: 0;
    background: linear-gradient(170deg, #053d27 0%, #0c3f7f 100%);
    flex-direction: column;
    align-items: stretch;
    padding: 1rem;
    gap: 0.5rem;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    z-index: 99;
  }
  .nav-links.open { display: flex; }
  .nav-btn {
    justify-content: center;
    padding: 10px 16px;
    font-size: 0.8rem;
    border-radius: var(--radius-md);
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.25);
  }
  .nav-portal-btn {
    display: inline-flex !important;
    justify-content: center;
    align-items: center;
    gap: 7px;
    padding: 10px 16px;
    font-size: 0.8rem;
    border-radius: var(--radius-md);
    background: rgba(255,255,255,0.15);
    border: 1.5px solid rgba(255,255,255,0.4);
    color: #fff;
    font-family: var(--mono);
    font-weight: 600;
    text-decoration: none;
    margin-top: 0.25rem;
  }
  .nav-portal-btn svg { width: 13px; height: 13px; flex-shrink: 0; }
  .signin-btn { display: none; }
  .projects-grid { grid-template-columns: 1fr; }
  .cta-btns { flex-direction: column; }
  .cta-btns .btn-light, .cta-btns .btn-ghost-light { justify-content: center; }
  .hero {
    min-height: unset;
    aspect-ratio: 16 / 9;
  }
}

/* Mobile portrait */
@media (max-width: 520px) {
  .team-grid { grid-template-columns: 1fr; }
  .hero-stats { grid-template-columns: 1fr 1fr; }
  .about-buttons { flex-direction: column; }
  .about-buttons .btn-dark, .about-buttons .btn-ghost { justify-content: center; }
  .footer { flex-direction: column; align-items: flex-start; gap: 0.8rem; }
  .footer-links { gap: 0.8rem; }
  .culture-item { grid-template-columns: auto 1fr; gap: 0.8rem; }
  .member-photo-wrap { height: clamp(200px, 60vw, 280px); }
}

/* Very small mobile */
@media (max-width: 360px) {
  .hero-stats { grid-template-columns: 1fr; }
  .brand-text .name { font-size: 0.78rem; }
  .signin-btn span { display: none; }
}
</style>
</head>
<body>

<!-- HEADER -->
<header class="header">
  <div class="brand">
    <div class="logo-img-wrap">
      <img src="images/bau-logo.png" alt="BAU Logo">
    </div>
    <div class="brand-text">
      <div class="name">Bangladesh Agricultural University</div>
      <div class="sub">Developers Page</div>
    </div>
  </div>
  <div class="header-sep"></div>

  <!-- Hamburger for mobile -->
  <button class="hamburger" id="hamburger" aria-label="Toggle navigation" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>

  <nav class="nav-links" id="nav-links">
    <a href="#about" class="nav-btn">About</a>
    <a href="#team" class="nav-btn">Team</a>
    <a href="#projects" class="nav-btn">Projects</a>
    <a href="#culture" class="nav-btn">Culture</a>
    <a href="#contact" class="nav-btn">Contact</a>
    <a href="index.php" class="nav-portal-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
        <polyline points="10 17 15 12 10 7"/>
        <line x1="15" y1="12" x2="3" y2="12"/>
      </svg>
      Back to Portal
    </a>
  </nav>

  <a href="index.php" class="signin-btn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
      <polyline points="10 17 15 12 10 7"/>
      <line x1="15" y1="12" x2="3" y2="12"/>
    </svg>
    <span>Back to Portal</span>
  </a>
</header>

<!-- HERO -->
<section class="hero">
  <div class="hero-content">
    <h1 class="hero-title reveal d1">
      Science at the<br>
      <em>edge</em> of<br>
      computation.
    </h1>
    <p class="hero-desc reveal d2">
      We bridge cutting-edge computational biology research and real-world
      application — built by three engineers who refused to accept the gap.
    </p>
  </div>
</section>

<!-- ABOUT -->
<section id="about">
  <div class="about-left">
    <h2 class="about-heading reveal d1">Who<br>We Are</h2>
    <div class="about-body reveal d2">
      <p>Tin_Ustad was founded in 2023 by three passionate Bioinformatics Engineering students who saw a gap between cutting-edge computational biology research and its practical application in the real world.</p>
      <p>What began as a university project quickly evolved into a mission to make advanced bioinformatics accessible to researchers, healthcare providers, and biotech startups worldwide.</p>
      <p>We work alongside our clients as partners in research — not vendors. Every project is a collaboration rooted in scientific rigor, computational precision, and shared curiosity.</p>
      <div class="about-buttons reveal d3">
        <a href="#team" class="btn-dark">Meet the Team
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </a>
        <a href="#contact" class="btn-ghost">Work With Us</a>
      </div>
    </div>
  </div>
  <div class="about-right">
    <div class="hero-stats reveal d1">
      <div class="stat">
        <div class="stat-num">5</div>
        <div class="stat-label">Projects Completed</div>
      </div>
      <div class="stat">
        <div class="stat-num">100%</div>
        <div class="stat-label">Client Satisfaction</div>
      </div>
      <div class="stat">
        <div class="stat-num">2023</div>
        <div class="stat-label">Founded</div>
      </div>
      <div class="stat">
        <div class="stat-num">3</div>
        <div class="stat-label">Co-Founders</div>
      </div>
    </div>
  </div>
</section>

<!-- TEAM -->
<section id="team">
  <div class="team-header">
    <h2 class="team-heading reveal d1">Our Team</h2>
  </div>
  <div class="team-grid">

    <div class="member-card reveal d1">
      <div class="member-photo-wrap">
        <img src="images/developers/profile/fahad.jpg" alt="Md. Fahad Hasan"
          onerror="this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--slate-200);font-size:2rem;color:var(--slate-500);\'>FH</div>'">
      </div>
      <div class="member-body">
        <h3 class="member-name">Md. Fahad Hasan</h3>
        <p class="member-role">Co-Founder</p>
        <p class="member-bio">Leads genomics research initiatives and company strategy. Passionate about making genomic analysis genuinely accessible.</p>
        <div class="tags"><span class="tag">NGS Analysis</span><span class="tag">Variant Calling</span><span class="tag">GWAS</span></div>
        <div class="member-links">
          <a href="https://github.com/Md-Fahad-Hasan" target="_blank" class="mlink" aria-label="GitHub">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
          </a>
          <a href="mailto:fahadhasan1292003@gmail.com" class="mlink" aria-label="Email">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </a>
        </div>
      </div>
    </div>

    <div class="member-card reveal d2">
      <div class="member-photo-wrap">
        <img src="images/developers/profile/rabbi.jpg" alt="A.K.M Fazle Hasan Rabbi Nur"
          onerror="this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--slate-200);font-size:2rem;color:var(--slate-500);\'>RN</div>'">
      </div>
      <div class="member-body">
        <h3 class="member-name">A.K.M Fazle Hasan Rabbi Nur</h3>
        <p class="member-role">Co-Founder</p>
        <p class="member-bio">Specializes in structural bioinformatics and leads technical development. Creates innovative computational solutions.</p>
        <div class="tags"><span class="tag">Protein Modeling</span><span class="tag">Molecular Dynamics</span><span class="tag">Drug Discovery</span></div>
        <div class="member-links">
          <a href="https://github.com" target="_blank" class="mlink" aria-label="GitHub">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
          </a>
          <a href="mailto:rabbi.2309027@bau.edu.bd" class="mlink" aria-label="Email">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </a>
        </div>
      </div>
    </div>

    <div class="member-card reveal d3">
      <div class="member-photo-wrap">
        <img src="images/developers/profile/afif.jpg" alt="Mohammod Didarul Anwar Afif"
          onerror="this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--slate-200);font-size:2rem;color:var(--slate-500);\'>DA</div>'">
      </div>
      <div class="member-body">
        <h3 class="member-name">Mohammod Didarul Anwar Afif</h3>
        <p class="member-role">Co-Founder</p>
        <p class="member-bio">Bridges biological insight and statistical modeling. Drives analytical pipelines for actionable research findings.</p>
        <div class="tags"><span class="tag">ML Pipelines</span><span class="tag">RNA-Seq</span><span class="tag">Statistics</span></div>
        <div class="member-links">
          <a href="https://github.com/afif004" target="_blank" class="mlink" aria-label="GitHub">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
          </a>
          <a href="mailto:afifdidar03@gmail.com" class="mlink" aria-label="Email">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </a>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- PROJECTS -->
<section id="projects">
  <div class="projects-header">
    <h2 class="projects-heading reveal d1">Projects We've<br>Delivered</h2>
  </div>
  <div class="projects-grid">
    <div class="project-card reveal d1">
      <div class="project-img"><img src="images/developers/projects/baubrainium.jpg" alt="BauBrainium" onerror="this.style.display='none'"></div>
      <div class="project-info"><h4>BauBrainium</h4><p>Social Media For BAU Alumni — connecting graduates across generations.</p></div>
    </div>
    <div class="project-card reveal d2">
      <div class="project-img"><img src="images/developers/projects/ovijan.jpg" alt="Ovijan" onerror="this.style.display='none'"></div>
      <div class="project-info"><h4>Ovijan</h4><p>BAU Live Bus Locator — real-time GPS tracking for campus transportation.</p></div>
    </div>
    <div class="project-card reveal d3">
      <div class="project-img"><img src="images/developers/projects/rms.jpg" alt="BAU Room Management System" onerror="this.style.display='none'"></div>
      <div class="project-info"><h4>BAU Room Management System</h4><p>Smart classroom scheduling and availability tracking across all faculties.</p></div>
    </div>
    <div class="project-card reveal d1">
      <div class="project-img"><img src="images/developers/projects/attendance.jpg" alt="Smart Attendance System" onerror="this.style.display='none'"></div>
      <div class="project-info"><h4>Smart Attendance System</h4><p>QR-based attendance with automated reporting and analytics.</p></div>
    </div>
    <div class="project-card reveal d2">
      <div class="project-img"><img src="images/developers/projects/vgid.jpg" alt="VGID Laboratory Website" onerror="this.style.display='none'"></div>
      <div class="project-info"><h4>VGID Laboratory Website</h4><p>Official research portal for Veterinary Genetics & Infectious Diseases lab.</p></div>
    </div>
  </div>
</section>

<!-- CULTURE -->
<section id="culture">
  <div class="culture-left">
    <h2 class="culture-heading reveal d1">Team<br>Culture</h2>
    <blockquote class="culture-quote reveal d2">"The best science happens when biologists, computer scientists, and statisticians stop talking past each other."</blockquote>
  </div>
  <div class="culture-right">
    <div class="culture-list reveal">
      <div class="culture-item"><span class="culture-num">01</span><div><h4 class="culture-title">Collaborative Innovation</h4><p class="culture-desc">We believe the best solutions emerge from interdisciplinary work — bio + CS + stats, every time.</p></div></div>
      <div class="culture-item"><span class="culture-num">02</span><div><h4 class="culture-title">Continuous Learning</h4><p class="culture-desc">Weekly sessions dedicated to learning new techniques and sharing knowledge across the team.</p></div></div>
      <div class="culture-item"><span class="culture-num">03</span><div><h4 class="culture-title">Open Science</h4><p class="culture-desc">We contribute to open-source bioinformatics tools and believe in transparent, reproducible research.</p></div></div>
      <div class="culture-item"><span class="culture-num">04</span><div><h4 class="culture-title">Diverse Perspectives</h4><p class="culture-desc">Actively seeking team members from different backgrounds to drive innovation through diversity.</p></div></div>
      <div class="culture-item"><span class="culture-num">05</span><div><h4 class="culture-title">Work-Life Harmony</h4><p class="culture-desc">Flexible schedules and mental health support — balanced people do their best science.</p></div></div>
      <div class="culture-item"><span class="culture-num">06</span><div><h4 class="culture-title">Joy in Discovery</h4><p class="culture-desc">We keep a curious, fun environment where the process of discovery is as rewarding as the result.</p></div></div>
    </div>
  </div>
</section>

<!-- CONTACT -->
<section id="contact">
  <div>
    <div class="cta-label">Get in Touch</div>
    <h2 class="cta-heading">Let's do<br><em>great</em><br>science.</h2>
  </div>
  <div class="cta-right">
    <p class="cta-text">Whether you're a researcher, a healthcare provider, or a biotech startup — if you have a biological problem that needs computational precision, we'd love to talk. No pitch decks required.</p>
    <div class="cta-btns">
      <a href="mailto:tinustad@gmail.com" class="btn-light">Email Us
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="5 12 19 12"/><line x1="12" y1="5" x2="12" y2="19"/></svg>
      </a>
      <a href="https://www.facebook.com/profile.php?id=61571514339862" target="_blank" class="btn-ghost-light">Facebook Page</a>
      <a href="https://github.com/TinUstad" target="_blank" class="btn-ghost-light">GitHub</a>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer class="footer">
  <div class="footer-left">
    <div class="fl1">Bangladesh Agricultural University · Classroom Management System</div>
    <div class="fl2">&copy; 2025 BAU · Built by Tin_Ustad (Bioinformatics Engineering)</div>
  </div>
  <div class="footer-links">
    <a href="index.php">Classroom Portal</a>
    <a href="#">Privacy</a>
    <a href="#">Support</a>
    <a href="mailto:tinustad@gmail.com">Contact Dev Team</a>
  </div>
</footer>

<script>
// Intersection observer for reveal animations
document.addEventListener('DOMContentLoaded', function() {
  const reveals = document.querySelectorAll('.reveal');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
      }
    });
  }, { threshold: 0.08 });
  reveals.forEach(el => observer.observe(el));

  // Hamburger menu toggle
  const hamburger = document.getElementById('hamburger');
  const navLinks = document.getElementById('nav-links');

  hamburger.addEventListener('click', function() {
    const isOpen = navLinks.classList.toggle('open');
    hamburger.classList.toggle('open', isOpen);
    hamburger.setAttribute('aria-expanded', isOpen);
  });

  // Close nav when a link is clicked
  navLinks.querySelectorAll('.nav-btn').forEach(link => {
    link.addEventListener('click', () => {
      navLinks.classList.remove('open');
      hamburger.classList.remove('open');
      hamburger.setAttribute('aria-expanded', 'false');
    });
  });

  // Close nav on outside click
  document.addEventListener('click', function(e) {
    if (!hamburger.contains(e.target) && !navLinks.contains(e.target)) {
      navLinks.classList.remove('open');
      hamburger.classList.remove('open');
      hamburger.setAttribute('aria-expanded', 'false');
    }
  });
});
</script>
</body>
</html>