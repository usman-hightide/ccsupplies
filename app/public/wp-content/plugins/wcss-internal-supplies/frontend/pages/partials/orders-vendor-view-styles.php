<style>
.aov-page .aov-header h1 { margin: 0; }
.aov-eyebrow {
  margin: 0 0 4px;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: #6b7280;
}
.aov-sub {
  margin: 4px 0 0;
  color: #6b7280;
  font-size: 13px;
}
.aov-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 18px 20px;
  box-shadow: 0 1px 2px rgba(16,24,40,.04);
  margin-bottom: 14px;
}
.aov-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}
.aov-card-head h3 {
  margin: 0;
  font-size: 16px;
}
.aov-summary-card { padding: 20px; }
.aov-summary-top {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  margin-bottom: 16px;
}
.aov-summary-top h2 {
  margin: 0;
  font-size: 24px;
  letter-spacing: -.02em;
}
.aov-summary-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}
.aov-badge {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.2;
}
.aov-badge-success { background: #ecfdf3; color: #067647; }
.aov-badge-danger { background: #fef3f2; color: #b42318; }
.aov-badge-warn { background: #fffaeb; color: #b54708; }
.aov-badge-muted { background: #f3f4f6; color: #4b5563; }
.aov-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  background: #f3f4f6;
  color: #4b5563;
  font-size: 12px;
  font-weight: 600;
}
.aov-meta-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}
.aov-fields-2col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 16px;
}
.aov-raw-block + .aov-raw-block { margin-top: 14px; }
.aov-raw-block h4 {
  margin: 0 0 8px;
  font-size: 13px;
  color: #374151;
}
@media (max-width: 900px) {
  .aov-fields-2col {
    grid-template-columns: 1fr;
  }
}
.aov-meta-item {
  background: #f8fafc;
  border: 1px solid #eef2f7;
  border-radius: 10px;
  padding: 10px 12px;
}
.aov-meta-item span {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #6b7280;
  margin-bottom: 4px;
}
.aov-meta-item strong {
  font-size: 13px;
  word-break: break-word;
}
.aov-grid-2 {
  display: grid;
  grid-template-columns: 1.2fr .8fr;
  gap: 14px;
  margin-bottom: 14px;
}
.aov-grid-2 > .aov-card { margin-bottom: 0; }
.aov-fields {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
.aov-fields-compact {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid #eef2f7;
}
.aov-field {
  display: grid;
  grid-template-columns: 140px 1fr;
  gap: 12px;
  align-items: start;
}
.aov-label {
  color: #6b7280;
  font-size: 13px;
}
.aov-value {
  font-weight: 600;
  font-size: 13px;
  color: #111827;
  word-break: break-word;
}
.aov-ship-name {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}
.aov-ship-company {
  margin-top: 2px;
  color: #4b5563;
  font-weight: 600;
}
.aov-ship-lines {
  margin-top: 10px;
  color: #374151;
  font-size: 14px;
  line-height: 1.55;
}
.aov-table-wrap {
  overflow: auto;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
}
.aov-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 640px;
}
.aov-table th {
  background: #f8fafc;
  color: #6b7280;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: .03em;
  text-align: left;
  padding: 10px 12px;
  border-bottom: 1px solid #e5e7eb;
}
.aov-table td {
  padding: 12px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: top;
  font-size: 13px;
}
.aov-table tr:last-child td { border-bottom: 0; }
.aov-table tbody tr:hover { background: #fafbfc; }
.aov-product-name { font-weight: 650; color: #111827; }
.aov-submeta { margin-top: 4px; color: #6b7280; font-size: 12px; }
.aov-num { text-align: right; white-space: nowrap; }
.aov-strong { font-weight: 700; }
.aov-code, .aov-meta-item code, .aov-submeta code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
  background: #f3f4f6;
  padding: 2px 6px;
  border-radius: 6px;
}
.aov-totals {
  display: flex;
  justify-content: flex-end;
  gap: 16px;
  align-items: center;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed #e5e7eb;
  color: #4b5563;
}
.aov-totals-stack {
  margin-top: 12px;
}
.aov-totals-stack .aov-totals:first-child {
  margin-top: 0;
}
.aov-totals-grand {
  border-top: 1px solid #d1d5db;
  padding-top: 10px;
  margin-top: 10px;
}
.aov-totals-grand strong {
  font-size: 17px;
}
.aov-totals strong {
  font-size: 16px;
  color: #111827;
}
.aov-details summary {
  cursor: pointer;
  font-weight: 700;
  list-style: none;
  display: flex;
  align-items: center;
  gap: 8px;
}
.aov-details summary::-webkit-details-marker { display: none; }
.aov-details[open] summary { margin-bottom: 12px; }
.aov-api-msg { color: #4b5563; margin: 0 0 10px; }
.aov-pre {
  margin: 0;
  white-space: pre-wrap;
  background: #0b1220;
  color: #e5e7eb;
  padding: 14px;
  border-radius: 10px;
  overflow: auto;
  font-size: 12px;
  line-height: 1.5;
}
.aov-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 4px 0 24px;
}
.aov-actions .btn {
  padding: 10px 14px;
  font-weight: 600;
  text-decoration: none;
}
.aov-actions .btn-primary {
  background: #111827;
  border-color: #111827;
  color: #fff;
}
.aov-empty {
  text-align: center;
  padding: 36px 24px;
}
.aov-empty-icon { font-size: 28px; margin-bottom: 8px; }
.aov-empty h2 { margin: 0 0 8px; }
.aov-empty-hint { color: #6b7280; }
.aov-muted { color: #6b7280; }
.aov-skeleton {
  background: #fff;
  border: 1px dashed #d1d5db;
  border-radius: 12px;
  padding: 24px;
  color: #6b7280;
}
@media (max-width: 900px) {
  .aov-meta-grid,
  .aov-grid-2 {
    grid-template-columns: 1fr;
  }
  .aov-summary-top {
    flex-direction: column;
  }
  .aov-field {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
