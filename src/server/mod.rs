use crate::server::settings::Settings;
use crate::Result;
use tokio::io::{stdin, BufReader, AsyncBufReadExt};
use tokio::sync::mpsc::channel;
use tokio::task::JoinHandle;


mod settings;

pub async fn new() -> Result<Server> {
    let mut path = std::env::current_dir()?;
    path.push("config.yml");
    let settings = settings::load(&path)?;
    Ok(Server {
        settings,
        running: false,
        threads: vec![]
    })
}

enum Receivable {
    Command(String),
    Event(Event),
}

enum Event {
}

pub struct Server {
    pub settings: Settings,
    running: bool,
    threads: Vec<JoinHandle<()>>,
}

impl Server {
    pub async fn start(&mut self) -> Result<()>  {
        self.running = true;
        let (mut tx, mut tr) = channel::<Receivable>(2048);
        self.threads.push(tokio::spawn(async move {
            loop {
                let mut inp = String::new();
                BufReader::new(stdin()).read_line(&mut inp).await.unwrap();
                tx.send(Receivable::Command(inp.trim().to_string())).await;
            }
        }));
        while self.running {
            let received = tr.recv().await.unwrap();
            match received {
                Receivable::Command(command) => {
                    match &*command {
                        "stop" => self.stop(),
                        _ => println!("Unknown command.")
                    }
                },
                Receivable::Event(event) => {

                }
            }
        }
        Ok(())
    }

    pub fn stop(&mut self) {
        println!("Shutting down the Lumine server...");
        self.running = false;
        std::process::exit(0);
    }
}