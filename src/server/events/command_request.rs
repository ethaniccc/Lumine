use lumine_protocol::CanIo;

#[derive(Debug, Clone)]
pub struct CommandRequest {
    pub sender: String,
    pub command: String,
    pub args: Vec<String>,
}

impl CanIo for CommandRequest {
    fn write(&self, vec: &mut Vec<u8>) {
        self.sender.write(vec);
        self.command.write(vec);
        //Write all as reader expects end of stream
        for arg in &self.args {
            arg.write(vec)
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> lumine_protocol::Result<Self> {
        let sender = String::read(src, offset)?;
        let command = String::read(src, offset)?;
        let mut args = Vec::with_capacity(src.len() - *offset);
        for _ in *offset..src.len() {
            args.push(String::read(src, offset)?)
        }
        Ok(Self {
            sender,
            command,
            args,
        })
    }
}
