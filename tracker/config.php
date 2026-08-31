<?php
/**
 * FDE Roadmap Progress Tracker — Shared Config
 */

define('MILESTONES_DIR', __DIR__ . '/../milestones/');
define('PROGRESS_FILE',  __DIR__ . '/progress.json');

$MILESTONES = [
    ['id'=>'01','file'=>'01-data-engineering-advanced-sql.md',   'title'=>'Advanced SQL',               'month'=>'M1','weeks'=>'W1–W2', 'priority'=>'P1','domain'=>'Data Engineering',    'objective'=>'Master Advanced SQL: window functions, recursive CTEs, EXPLAIN ANALYZE optimization','deliverable'=>'Optimized benchmark queries on 10M+ row dataset'],
    ['id'=>'02','file'=>'02-data-pipelines-cdc.md',              'title'=>'Data Pipelines & CDC',        'month'=>'M1','weeks'=>'W1–W2', 'priority'=>'P1','domain'=>'Data Pipelines',       'objective'=>'Implement Change Data Capture with PostgreSQL, Debezium, and Kafka/NATS','deliverable'=>'Working local CDC pipeline syncing DB writes to consumer'],
    ['id'=>'03','file'=>'03-db-internals-oltp-olap.md',          'title'=>'DB Internals: OLTP vs OLAP',  'month'=>'M1','weeks'=>'W3–W4', 'priority'=>'P2','domain'=>'DB Internals',         'objective'=>'Study OLTP vs. OLAP data modeling (Star Schema, OBT) & indexing strategies','deliverable'=>'Schema design doc comparing OLTP vs OLAP performance'],
    ['id'=>'04','file'=>'04-glue-architecture-resilient-http.md','title'=>'Resilient HTTP Client',       'month'=>'M1','weeks'=>'W3–W4', 'priority'=>'P2','domain'=>'Glue Architecture',    'objective'=>'Build robust error-handling & retry wrappers with backoff for unreliable APIs','deliverable'=>'Reusable Python/Go resilient HTTP client package'],
    ['id'=>'05','file'=>'05-containerization-k8s-helm.md',       'title'=>'Containerization & K8s',      'month'=>'M2','weeks'=>'W5–W6', 'priority'=>'P1','domain'=>'DevOps / K8s',         'objective'=>'Containerize multi-service apps with Docker Compose and migrate to Kubernetes (Kind/Helm)','deliverable'=>'Local Helm chart deploying API, DB, and caching layer'],
    ['id'=>'06','file'=>'06-infra-as-code-terraform.md',         'title'=>'Infra as Code: Terraform',    'month'=>'M2','weeks'=>'W5–W6', 'priority'=>'P2','domain'=>'Infrastructure',       'objective'=>'Write Terraform scripts to provision cloud resources (VPCs, subnets, IAM, RDS)','deliverable'=>'Declarative IaC repo with modular Terraform templates'],
    ['id'=>'07','file'=>'07-enterprise-security-oauth2-oidc-saml.md','title'=>'Enterprise Security',     'month'=>'M2','weeks'=>'W7–W8', 'priority'=>'P2','domain'=>'Security',             'objective'=>'Implement OAuth2, OIDC, and SAML 2.0 single sign-on; set up RBAC permission schemas','deliverable'=>'Working Auth service integration with Okta/Keycloak'],
    ['id'=>'08','file'=>'08-network-security-vpc-tls.md',        'title'=>'Network Security & TLS',      'month'=>'M2','weeks'=>'W7–W8', 'priority'=>'P3','domain'=>'Network Security',     'objective'=>'Learn enterprise network traversal: reverse proxies (Nginx), TLS termination, VPC peering','deliverable'=>'Architecture diagram & setup guide for secure VPC access'],
    ['id'=>'09','file'=>'09-vector-databases.md',                'title'=>'Vector Databases',             'month'=>'M3','weeks'=>'W9–W10','priority'=>'P1','domain'=>'Vector Databases',    'objective'=>'Master vector indexing (HNSW, IVFFlat) and implement pgvector / Pinecone / Qdrant','deliverable'=>'Benchmark report comparing vector search latency & recall'],
    ['id'=>'10','file'=>'10-rag-systems-hybrid-search.md',       'title'=>'RAG: Hybrid Search',          'month'=>'M3','weeks'=>'W9–W10','priority'=>'P1','domain'=>'RAG Systems',         'objective'=>'Build end-to-end Hybrid RAG (Dense + Sparse BM25 + Reranking)','deliverable'=>'Hybrid search engine with re-ranking delivering top accuracy'],
    ['id'=>'11','file'=>'11-agentic-workflows-langgraph.md',     'title'=>'Agentic Workflows',           'month'=>'M3','weeks'=>'W11–W12','priority'=>'P1','domain'=>'AI Agents',           'objective'=>'Construct stateful multi-agent workflows using LangGraph / AutoGen','deliverable'=>'Multi-agent assistant executing SQL queries & API calls'],
    ['id'=>'12','file'=>'12-llm-guardrails-structured-output.md','title'=>'LLM Guardrails',              'month'=>'M3','weeks'=>'W11–W12','priority'=>'P2','domain'=>'LLM Engineering',    'objective'=>'Integrate structured data extraction (Pydantic, Instructor) and safety guardrails','deliverable'=>'Production API enforcing strict JSON output schemas'],
    ['id'=>'13','file'=>'13-llm-evaluation-ragas-deepeval.md',   'title'=>'LLM Evaluation',              'month'=>'M4','weeks'=>'W13–W14','priority'=>'P1','domain'=>'LLM Evaluation',     'objective'=>'Build automated evaluation pipelines (Ragas/DeepEval) measuring Faithfulness, Recall','deliverable'=>'Automated test harness scoring LLM responses against golden dataset'],
    ['id'=>'14','file'=>'14-golden-datasets.md',                 'title'=>'Golden Datasets',             'month'=>'M4','weeks'=>'W13–W14','priority'=>'P1','domain'=>'Data Curation',      'objective'=>'Curate a 100-sample domain-specific golden evaluation dataset with ground truth annotations','deliverable'=>'Structured evaluation benchmark dataset (JSON/CSV)'],
    ['id'=>'15','file'=>'15-telemetry-tracing-opentelemetry.md', 'title'=>'Telemetry & Tracing',         'month'=>'M4','weeks'=>'W15–W16','priority'=>'P2','domain'=>'Observability',      'objective'=>'Implement OpenTelemetry / LangSmith / Phoenix for LLM trace logging, token counting, cost tracking','deliverable'=>'Dashboard tracking latency, token usage, and cost per call'],
    ['id'=>'16','file'=>'16-fine-tuning-lora-qlora.md',          'title'=>'Fine-Tuning: LoRA/QLoRA',     'month'=>'M4','weeks'=>'W15–W16','priority'=>'P3','domain'=>'Fine-Tuning',        'objective'=>'Learn LoRA/QLoRA fine-tuning concepts for open-source models (Llama 3 / Mistral)','deliverable'=>'Fine-tuned small model benchmarked against base model'],
    ['id'=>'17','file'=>'17-problem-scoping-requirements.md',    'title'=>'Problem Scoping & RFCs',      'month'=>'M5','weeks'=>'W17–W18','priority'=>'P1','domain'=>'Client Skills',       'objective'=>'Practice live requirements decomposition: convert vague executive requests into technical RFCs','deliverable'=>'3 comprehensive Technical Requirement & Specs'],
    ['id'=>'18','file'=>'18-executive-pitching-pyramid-principle.md','title'=>'Executive Pitching',       'month'=>'M5','weeks'=>'W17–W18','priority'=>'P2','domain'=>'Communication',      'objective'=>'Master Pyramid Principle communication (Lead with conclusion, group arguments, summarize)','deliverable'=>'Executive presentation deck for complex tech integration'],
    ['id'=>'19','file'=>'19-rapid-prototyping-48hr-sprints.md',  'title'=>'Rapid Prototyping (48hr)',     'month'=>'M5','weeks'=>'W19–W20','priority'=>'P2','domain'=>'Prototyping',        'objective'=>'Execute 48-hour sprints to build full-stack vertical slices (Streamlit/Next.js + FastAPI)','deliverable'=>'2 end-to-end working MVP prototypes built under tight deadlines'],
    ['id'=>'20','file'=>'20-client-objections-playbook.md',      'title'=>'Client Objections Playbook',  'month'=>'M5','weeks'=>'W19–W20','priority'=>'P3','domain'=>'Sales/Consulting',   'objective'=>'Study common enterprise objections (Security, Lock-in, On-Prem) and master responses','deliverable'=>'Playbook on resolving enterprise technical friction points'],
    ['id'=>'21','file'=>'21-portfolio-project-1-rag-pipeline.md','title'=>'Portfolio: RAG Pipeline',     'month'=>'M6','weeks'=>'W21–W22','priority'=>'P1','domain'=>'Portfolio',           'objective'=>'Build Flagship Project 1: Enterprise Hybrid RAG + Eval Pipeline with CI/CD & Terraform','deliverable'=>'GitHub Repo with live demo, docs, and benchmark report'],
    ['id'=>'22','file'=>'22-portfolio-project-2-cdc-pipeline.md','title'=>'Portfolio: CDC Pipeline',     'month'=>'M6','weeks'=>'W21–W22','priority'=>'P1','domain'=>'Portfolio',           'objective'=>'Build Flagship Project 2: Real-time Data Integration & Transformation Engine with CDC','deliverable'=>'GitHub Repo featuring end-to-end architecture & tests'],
    ['id'=>'23','file'=>'23-fde-interview-prep.md',              'title'=>'FDE Interview Prep',           'month'=>'M6','weeks'=>'W23–W24','priority'=>'P1','domain'=>'Interview Prep',     'objective'=>'Practice 5 Live Scoping sessions & 5 Unfamiliar Codebase Bug Investigations','deliverable'=>'Interview feedback log with documented improvements'],
    ['id'=>'24','file'=>'24-system-design-enterprise.md',        'title'=>'Enterprise System Design',     'month'=>'M6','weeks'=>'W23–W24','priority'=>'P2','domain'=>'System Design',      'objective'=>'Review Enterprise System Design: hybrid cloud, data sync, air-gapped deployments, rate limiting','deliverable'=>'System design cheat sheet for enterprise architectures'],
];

/** Load saved progress from JSON file */
function load_progress(): array {
    if (!file_exists(PROGRESS_FILE)) return [];
    $data = json_decode(file_get_contents(PROGRESS_FILE), true);
    return is_array($data) ? $data : [];
}

/** Save progress to JSON file */
function save_progress(array $progress): bool {
    return (bool) file_put_contents(PROGRESS_FILE, json_encode($progress, JSON_PRETTY_PRINT));
}

/** Parse checklist items from a milestone MD file (only items after ## Checklist heading) */
function parse_checklist(string $filepath): array {
    if (!file_exists($filepath)) return [];
    $lines   = file($filepath, FILE_IGNORE_NEW_LINES);
    $items   = [];
    $in_checklist = false;

    foreach ($lines as $line) {
        // Start collecting after the Checklist heading
        if (preg_match('/^##\s+Checklist\s*$/i', trim($line))) {
            $in_checklist = true;
            continue;
        }
        // Stop at next heading (but not sub-headings within checklist)
        if ($in_checklist && preg_match('/^##\s+/', $line) && !preg_match('/^##\s+Checklist/i', $line)) {
            break;
        }
        if ($in_checklist) {
            // Match both unchecked "- [ ]" and checked "- [x]"
            if (preg_match('/^- \[([ x])\]\s+(.+)$/i', $line, $m)) {
                $items[] = [
                    'text'    => trim($m[2]),
                    'default' => strtolower($m[1]) === 'x',
                ];
            }
        }
    }
    return $items;
}

/** Get completion stats for a milestone */
function milestone_stats(string $id, array $progress, array $tasks): array {
    $total     = count($tasks);
    if ($total === 0) return ['total'=>0,'done'=>0,'pct'=>0];
    $done = 0;
    foreach ($tasks as $i => $task) {
        if (!empty($progress[$id][(string)$i]) || $task['default']) {
            $done++;
        }
    }
    // Merge with saved progress (saved overrides default)
    $done = 0;
    foreach ($tasks as $i => $task) {
        $saved = $progress[$id][(string)$i] ?? null;
        if ($saved === true || ($saved === null && $task['default'])) {
            $done++;
        }
    }
    return ['total'=>$total,'done'=>$done,'pct'=>$total>0 ? round($done/$total*100) : 0];
}

/** Priority config */
function priority_badge(string $p): array {
    return match($p) {
        'P1' => ['label'=>'P1 — Critical','class'=>'p1','color'=>'#ef4444'],
        'P2' => ['label'=>'P2 — High',    'class'=>'p2','color'=>'#f97316'],
        'P3' => ['label'=>'P3 — Medium',  'class'=>'p3','color'=>'#eab308'],
        default => ['label'=>$p,           'class'=>'p0','color'=>'#6b7280'],
    };
}

/** Month label and color */
function month_config(string $m): array {
    return match($m) {
        'M1' => ['label'=>'Month 1','subtitle'=>'Data Engineering Foundation', 'color'=>'#3b82f6'],
        'M2' => ['label'=>'Month 2','subtitle'=>'Infrastructure & Security',   'color'=>'#8b5cf6'],
        'M3' => ['label'=>'Month 3','subtitle'=>'AI/ML Core — RAG & Agents',   'color'=>'#10b981'],
        'M4' => ['label'=>'Month 4','subtitle'=>'Evaluation & Observability',  'color'=>'#f59e0b'],
        'M5' => ['label'=>'Month 5','subtitle'=>'Client-Facing Skills',        'color'=>'#ec4899'],
        'M6' => ['label'=>'Month 6','subtitle'=>'Portfolio & Interview Prep',  'color'=>'#14b8a6'],
        default => ['label'=>$m,'subtitle'=>'','color'=>'#6b7280'],
    };
}
